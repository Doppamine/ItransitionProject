<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AttributeLibraryPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AttributeCategory $category;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->category = new AttributeCategory('Library '.bin2hex(random_bytes(5)));
        $this->user = new User('library-'.bin2hex(random_bytes(5)).'@example.test');
        $this->user->setRoles(['ROLE_RECRUITER']);
        $this->em->persist($this->category);
        $this->em->persist($this->user);
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            $this->em->clear();
        }
        parent::tearDown();
    }

    public function testRolesAndTableNavigation(): void
    {
        $this->client->request('GET', '/attributes');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table thead th');
        self::assertSelectorExists('nav a[href="/attributes"]');
        $admin = new User('admin-'.bin2hex(random_bytes(5)).'@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/attributes');
        self::assertResponseIsSuccessful();
        $candidate = new User('candidate-'.bin2hex(random_bytes(5)).'@example.test');
        $candidate->setRoles(['ROLE_CANDIDATE']);
        $this->em->persist($candidate);
        $this->em->flush();
        $this->client->loginUser($candidate);
        $this->client->request('GET', '/attributes');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/attributes/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->restart();
        $this->client->request('GET', '/attributes');
        self::assertResponseRedirects('/login');
    }

    public function testSearchFiltersNameCategoryAndType(): void
    {
        $other = new AttributeCategory('Other '.bin2hex(random_bytes(4)));
        $this->em->persist($other);
        $this->definition('Library Alpha', AttributeType::TEXT);
        $this->definition('Library Beta', AttributeType::STRING);
        $this->definition('Library Elsewhere', AttributeType::TEXT, $other);
        $this->em->flush();
        $this->client->request('GET', '/attributes?q=LIBRARY&category='.$this->category->getId().'&type=text');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Library Alpha');
        self::assertSelectorTextNotContains('table', 'Library Beta');
        self::assertSelectorTextNotContains('table', 'Library Elsewhere');
    }

    public function testCreateDuplicateAndCandidateDiscovery(): void
    {
        $name = 'Recruiter language '.bin2hex(random_bytes(4));
        $this->submitDefinition('/attributes/new', $name, AttributeType::STRING);
        self::assertResponseRedirects('/attributes');
        $definition = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => mb_strtolower($name)]);
        self::assertNotNull($definition);
        $this->submitDefinition('/attributes/new', ' '.mb_strtoupper($name).' ', AttributeType::STRING);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_definition WHERE normalized_name = ?', [mb_strtolower($name)]));

        $candidate = new User('candidate-'.bin2hex(random_bytes(5)).'@example.test');
        $candidate->setRoles(['ROLE_CANDIDATE']);
        $this->em->persist($candidate);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $this->em->persist(Profile::createWithBuiltIns($candidate, $builtIns));
        $this->em->flush();
        $this->client->loginUser($candidate);
        $this->client->request('GET', '/profile?q=recruiter');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#attribute-results', $name);
    }

    public function testEditMetadataAndUnusedTypeButRejectUsedType(): void
    {
        $definition = $this->definition('Editable', AttributeType::STRING);
        $secondCategory = new AttributeCategory('Second '.bin2hex(random_bytes(4)));
        $this->em->persist($secondCategory);
        $this->em->flush();
        $this->submitDefinition('/attributes/'.$definition->getId().'/edit', 'Renamed', AttributeType::TEXT, 'Updated description', $secondCategory);
        self::assertResponseRedirects('/attributes/'.$definition->getId().'/edit');
        $stored = $this->storedDefinition($definition);
        self::assertSame('Renamed', $stored['name']);
        self::assertSame('text', $stored['type']);
        self::assertSame('Updated description', $stored['description']);
        self::assertSame((string) $secondCategory->getId(), (string) $this->em->getConnection()->fetchOne('SELECT category_id FROM attribute_definition WHERE id = ?', [$definition->getId()]));

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $profile = Profile::createWithBuiltIns($this->em->find(User::class, $this->user->getId()), $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
        $this->em->persist($profile);
        $profile->selectAttribute($this->em->find(AttributeDefinition::class, $definition->getId()));
        $this->em->flush();
        $path = '/attributes/'.$definition->getId().'/edit';
        $this->client->request('GET', $path);
        self::assertSelectorExists('select[name="attribute_definition[type]"][disabled]');
        $token = $this->client->getCrawler()->filter('input[name="attribute_definition[_token]"]')->attr('value');
        $version = $this->client->getCrawler()->filter('input[name="attribute_definition[version]"]')->attr('value');
        $this->client->request('POST', $path, ['attribute_definition' => ['name' => 'Renamed', 'category' => (string) $secondCategory->getId(), 'description' => '', 'type' => 'date', 'version' => $version, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('text', $this->storedDefinition($definition)['type']);
    }

    public function testBuiltInMutationAndDeleteAreRejected(): void
    {
        $builtIn = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $path = '/attributes/'.$builtIn->getId().'/edit';
        $this->client->request('GET', $path);
        $token = $this->client->getCrawler()->filter('input[name="attribute_definition[_token]"]')->attr('value');
        $this->client->request('POST', $path, ['attribute_definition' => ['name' => 'Alias', 'category' => (string) $this->category->getId(), 'description' => '', 'type' => 'text', 'version' => (string) $builtIn->getVersion(), '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('First Name', $this->storedDefinition($builtIn)['name']);
        $token = $this->csrfToken('attribute_delete_'.$builtIn->getId());
        $this->client->request('POST', '/attributes/'.$builtIn->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteAndCsrfProtection(): void
    {
        $definition = $this->definition('Disposable', AttributeType::STRING);
        $this->em->flush();
        $path = '/attributes/'.$definition->getId().'/delete';
        $this->client->request('POST', $path, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->getRepository(AttributeDefinition::class)->find($definition->getId()));
        $this->client->request('GET', '/attributes/'.$definition->getId().'/edit');
        $token = $this->client->getCrawler()->filter('form[data-delete] input[name="_token"]')->attr('value');
        $this->client->request('POST', $path, ['_token' => $token]);
        self::assertResponseRedirects('/attributes');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_definition WHERE id = ?', [$definition->getId()]));
    }

    public function testDeletingOrdinaryDefinitionCascadesItsProfileValues(): void
    {
        $definition = $this->definition('Temporary', AttributeType::SELECT);
        $option = $definition->addOption('Temporary option', 10);
        $profile = Profile::createWithBuiltIns($this->user, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
        $profile->selectAttribute($definition)->setOption($option);
        $this->em->persist($profile);
        $this->em->flush();
        $id = $definition->getId();
        $this->client->request('GET', '/attributes/'.$id.'/edit');
        $token = $this->client->getCrawler()->filter('form[data-delete] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/attributes/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/attributes');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile_attribute_value WHERE attribute_definition_id = ?', [$id]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_option WHERE attribute_definition_id = ?', [$id]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile WHERE id = ?', [$profile->getId()]));
    }

    public function testTypeChangeRejectsValueSelectedAfterFormLoaded(): void
    {
        $definition = $this->definition('Concurrent choice', AttributeType::STRING);
        $this->em->flush();
        $path = '/attributes/'.$definition->getId().'/edit';
        $this->client->request('GET', $path);
        $token = $this->client->getCrawler()->filter('input[name="attribute_definition[_token]"]')->attr('value');
        $version = $this->client->getCrawler()->filter('input[name="attribute_definition[version]"]')->attr('value');

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $candidate = new User('late-candidate-'.bin2hex(random_bytes(5)).'@example.test');
        $candidate->setRoles(['ROLE_CANDIDATE']);
        $this->em->persist($candidate);
        $profile = Profile::createWithBuiltIns($candidate, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
        $profile->selectAttribute($this->em->find(AttributeDefinition::class, $definition->getId()));
        $this->em->persist($profile);
        $this->em->flush();

        $this->client->request('POST', $path, ['attribute_definition' => ['name' => $definition->getName(), 'category' => (string) $this->category->getId(), 'description' => '', 'type' => 'text', 'version' => $version, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('string', $this->storedDefinition($definition)['type']);
    }

    public function testOptionsAddRenameOrderingAndDuplicate(): void
    {
        $definition = $this->definition('Select', AttributeType::SELECT);
        $this->em->flush();
        $this->optionPost($definition, 'options', ['label' => 'English']);
        self::assertResponseRedirects('/attributes/'.$definition->getId().'/edit');
        $this->optionPost($definition, 'options', ['label' => 'French']);
        $options = $this->storedOptions($definition);
        self::assertSame([10, 20], array_map('intval', array_column($options, 'sort_order')));
        $this->optionPost($definition, 'options/'.$options[0]['id'].'/rename', ['label' => 'German']);
        self::assertSame('German', $this->storedOptions($definition)[0]['label']);
        $this->optionPost($definition, 'options', ['label' => 'German']);
        self::assertCount(2, $this->storedOptions($definition));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'already exists');
    }

    public function testNonSelectAndReferencedOptionCannotBeChangedOrRemoved(): void
    {
        $plain = $this->definition('Plain', AttributeType::STRING);
        $select = $this->definition('Select', AttributeType::SELECT);
        $option = $select->addOption('In use', 10);
        $profile = Profile::createWithBuiltIns($this->user, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
        $profile->selectAttribute($select)->setOption($option);
        $this->em->persist($profile);
        $this->em->flush();
        $this->client->request('GET', '/attributes/'.$plain->getId().'/edit');
        self::assertSelectorNotExists('form[data-option-add]');
        $this->client->request('POST', '/attributes/'.$plain->getId().'/options', ['label' => 'Illegal', '_token' => $this->csrfToken('attribute_option_'.$plain->getId())]);
        self::assertResponseStatusCodeSame(422);
        $this->optionPost($select, 'options/'.$option->getId().'/remove');
        self::assertResponseRedirects('/attributes/'.$select->getId().'/edit');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_option WHERE id = ?', [$option->getId()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'in use');
    }

    public function testUnreferencedOptionRemovalAndBadCsrf(): void
    {
        $definition = $this->definition('Select', AttributeType::SELECT);
        $option = $definition->addOption('Unused', 10);
        $this->em->flush();
        $path = '/attributes/'.$definition->getId().'/options/'.$option->getId().'/remove';
        $this->client->request('POST', $path, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->optionPost($definition, 'options/'.$option->getId().'/remove');
        self::assertResponseRedirects('/attributes/'.$definition->getId().'/edit');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_option WHERE id = ?', [$option->getId()]));
    }

    public function testStaleEditAndBadFormCsrfCannotOverwrite(): void
    {
        $definition = $this->definition('Current', AttributeType::STRING);
        $this->em->flush();
        $path = '/attributes/'.$definition->getId().'/edit';
        $this->client->request('GET', $path);
        $oldVersion = $definition->getVersion();
        $token = $this->client->getCrawler()->filter('input[name="attribute_definition[_token]"]')->attr('value');
        $definition->changeDescription('Newer');
        $this->em->flush();
        $this->client->request('POST', $path, ['attribute_definition' => ['name' => 'Stale', 'category' => (string) $this->category->getId(), 'description' => '', 'type' => 'string', 'version' => (string) $oldVersion, '_token' => $token]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Current '.substr($definition->getName(), -8), $this->storedDefinition($definition)['name']);
        $this->client->request('POST', $path, ['attribute_definition' => ['name' => 'No CSRF', 'category' => (string) $this->category->getId(), 'description' => '', 'type' => 'string', 'version' => (string) $definition->getVersion(), '_token' => 'bad']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Current '.substr($definition->getName(), -8), $this->storedDefinition($definition)['name']);
    }

    #[DataProvider('missingRequiredAttributeChoices')]
    public function testMissingRequiredAttributeChoiceCannotCreateOrUpdate(bool $editing, string $field, string $message): void
    {
        $definition = $editing ? $this->definition('Unchanged attribute', AttributeType::STRING) : null;
        $this->em->flush();
        $path = $definition === null ? '/attributes/new' : '/attributes/'.$definition->getId().'/edit';
        $this->client->request('GET', $path);
        $data = $this->client->getCrawler()->selectButton('Save attribute')->form()->getPhpValues()['attribute_definition'];
        $data = array_replace($data, ['name' => 'Rejected attribute', 'category' => (string) $this->category->getId(), 'type' => 'string']);
        unset($data[$field]);

        $this->client->request('POST', $path, ['attribute_definition' => $data]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $message);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_definition WHERE name = ?', ['Rejected attribute']));
        if ($definition !== null) {
            self::assertSame($definition->getName(), $this->storedDefinition($definition)['name']);
        }
    }

    public static function missingRequiredAttributeChoices(): iterable
    {
        foreach (['create' => false, 'edit' => true] as $operation => $editing) {
            yield $operation.' category' => [$editing, 'category', 'Choose a category.'];
            yield $operation.' type' => [$editing, 'type', 'Choose an attribute type.'];
        }
    }

    public function testAttributeNameLengthAlsoChecksStoredNormalizedName(): void
    {
        $name = str_repeat('İ', 128);
        $this->submitDefinition('/attributes/new', $name, AttributeType::STRING);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Enter a name of at most 255 characters.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attribute_definition WHERE name = ?', [$name]));

        $this->category = $this->em->find(AttributeCategory::class, $this->category->getId());
        $definition = $this->definition('Unchanged name', AttributeType::STRING);
        $this->em->flush();
        $this->submitDefinition('/attributes/'.$definition->getId().'/edit', $name, AttributeType::STRING);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Enter a name of at most 255 characters.');
        self::assertSame($definition->getName(), $this->storedDefinition($definition)['name']);
    }

    private function definition(string $name, AttributeType $type, ?AttributeCategory $category = null): AttributeDefinition
    {
        $definition = new AttributeDefinition($category ?? $this->category, $name.' '.bin2hex(random_bytes(4)), $type);
        $this->em->persist($definition);
        return $definition;
    }

    private function submitDefinition(string $path, string $name, AttributeType $type, string $description = '', ?AttributeCategory $category = null): void
    {
        $this->client->request('GET', $path);
        $form = $this->client->getCrawler()->selectButton('Save attribute')->form();
        $form['attribute_definition[name]'] = $name;
        $form['attribute_definition[category]'] = (string) ($category ?? $this->category)->getId();
        $form['attribute_definition[description]'] = $description;
        $form['attribute_definition[type]'] = $type->value;
        $this->client->submit($form);
    }

    private function optionPost(AttributeDefinition $definition, string $suffix, array $data = []): void
    {
        $this->client->request('GET', '/attributes/'.$definition->getId().'/edit');
        $token = $this->csrfToken('attribute_option_'.$definition->getId());
        $this->client->request('POST', '/attributes/'.$definition->getId().'/'.$suffix, $data + ['_token' => $token]);
    }

    private function storedDefinition(AttributeDefinition $definition): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT name, description, type FROM attribute_definition WHERE id = ?', [$definition->getId()]);
    }

    private function storedOptions(AttributeDefinition $definition): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT id, label, sort_order FROM attribute_option WHERE attribute_definition_id = ? ORDER BY sort_order, id', [$definition->getId()]);
    }

    private function csrfToken(string $id): string
    {
        $cookie = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($cookie);
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->setId($cookie->getValue());
        $request = \Symfony\Component\HttpFoundation\Request::create('/');
        $request->setSession($session);
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);
        try {
            return (string) static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $session->save();
            $stack->pop();
        }
    }
}
