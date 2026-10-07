<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use App\Repository\AttributeDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PositionPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AttributeCategory $category;
    private User $recruiter;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->category = new AttributeCategory('Positions '.bin2hex(random_bytes(4)));
        $this->recruiter = new User('recruiter-'.bin2hex(random_bytes(5)).'@example.test');
        $this->recruiter->setRoles(['ROLE_RECRUITER']);
        $this->em->persist($this->category);
        $this->em->persist($this->recruiter);
        $this->em->flush();
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

    public function testAnonymousSeesOnlyPublicPositionsAndCannotMutate(): void
    {
        $public = $this->position('Public role', PositionAccessType::PUBLIC);
        $restricted = $this->position('Private role', PositionAccessType::RESTRICTED);
        $this->em->flush();
        $this->client->request('GET', '/positions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
        self::assertSelectorTextContains('body', 'Public role');
        self::assertSelectorTextNotContains('body', 'Private role');
        self::assertSelectorExists('nav a[href="/positions"]');
        $this->client->request('GET', '/positions/'.$public->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/positions/'.$restricted->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/positions/new');
        self::assertResponseRedirects('/login');
    }

    public function testCandidateSeesPublicAndEligibleRestrictedWithDirectUrlProtection(): void
    {
        $score = $this->definition('Candidate score', AttributeType::NUMERIC);
        $candidate = new User('candidate-'.bin2hex(random_bytes(5)).'@example.test');
        $candidate->setRoles(['ROLE_CANDIDATE']);
        $profile = Profile::createWithBuiltIns($candidate, []);
        $profile->selectAttribute($score)->setNumeric('7.5');
        $this->em->persist($candidate);
        $this->em->persist($profile);
        $public = $this->position('Open position', PositionAccessType::PUBLIC);
        $eligible = $this->position('Eligible position', PositionAccessType::RESTRICTED);
        (new PositionAccessRule($eligible, $score, AccessRuleOperator::GREATER_THAN))->setNumericExpected('7.0');
        $ineligible = $this->position('Hidden position', PositionAccessType::RESTRICTED);
        (new PositionAccessRule($ineligible, $score, AccessRuleOperator::GREATER_THAN))->setNumericExpected('8.0');
        $this->em->flush();
        $this->client->loginUser($candidate);
        $this->client->request('GET', '/positions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Open position');
        self::assertSelectorTextContains('body', 'Eligible position');
        self::assertSelectorTextNotContains('body', 'Hidden position');
        $this->client->request('GET', '/positions/'.$eligible->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Access rules');
        $this->client->request('GET', '/positions/'.$ineligible->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/positions/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRecruiterAndAdminManageTheSharedPositionPool(): void
    {
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/positions/new');
        $form = $this->client->getCrawler()->selectButton('Save position')->form();
        $form['position[title]'] = 'Shared role';
        $form['position[shortDescription]'] = 'Shared description';
        $form['position[accessType]'] = PositionAccessType::RESTRICTED->value;
        $form['position[maxProjects]'] = '2';
        $this->client->submit($form);
        self::assertResponseRedirects('/positions');
        $position = $this->em->getRepository(Position::class)->findOneBy(['title' => 'Shared role']);
        self::assertNotNull($position);

        $admin = new User('admin-'.bin2hex(random_bytes(5)).'@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/positions/'.$position->getId().'/edit');
        self::assertResponseIsSuccessful();
        $token = $this->csrfToken('position_duplicate_'.$position->getId());
        $this->client->request('POST', '/positions/'.$position->getId().'/duplicate', ['_token' => $token]);
        self::assertResponseRedirects('/positions');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position WHERE title = ?', ['Shared role — Copy']));
        $token = $this->csrfToken('position_delete_'.$position->getId());
        $this->client->request('POST', '/positions/'.$position->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/positions');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position WHERE id = ?', [$position->getId()]));
    }

    public function testTemplateAttributesAndExistingTagsAreUniqueAndManageable(): void
    {
        $position = $this->position('Configured role', PositionAccessType::PUBLIC);
        $definition = $this->definition('Template item', AttributeType::TEXT);
        $tag = new Tag('Symfony '.bin2hex(random_bytes(3)));
        $this->em->persist($tag);
        $this->em->flush();
        $this->client->loginUser($this->recruiter);
        $version = (string) $position->getVersion();
        $token = $this->csrfToken('position_child_'.$position->getId());
        $payload = ['_token' => $token, 'version' => $version, 'definition' => (string) $definition->getId(), 'sortOrder' => '10'];
        $this->client->request('POST', '/positions/'.$position->getId().'/attributes/add', $payload);
        self::assertResponseRedirects('/positions/'.$position->getId().'/edit');
        $version = (string) $this->em->getConnection()->fetchOne('SELECT version FROM position WHERE id = ?', [$position->getId()]);
        $this->client->request('POST', '/positions/'.$position->getId().'/attributes/add', array_replace($payload, ['version' => $version]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position_attribute WHERE position_id = ?', [$position->getId()]));
        $version = (string) $this->em->getConnection()->fetchOne('SELECT version FROM position WHERE id = ?', [$position->getId()]);
        $this->client->request('POST', '/positions/'.$position->getId().'/tags/add', ['_token' => $token, 'version' => $version, 'tag' => $tag->getName()]);
        self::assertResponseRedirects('/positions/'.$position->getId().'/edit');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position_project_tag WHERE position_id = ?', [$position->getId()]));
    }

    public function testInvalidRuleAndStaleBasicOrChildMutationAreRejected(): void
    {
        $position = $this->position('Concurrent role', PositionAccessType::RESTRICTED);
        $boolean = $this->definition('Boolean rule', AttributeType::BOOLEAN);
        $image = $this->definition('Image rule', AttributeType::IMAGE);
        $template = $this->definition('Stale child', AttributeType::STRING);
        $this->em->flush();
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/positions/'.$position->getId().'/edit');
        $oldVersion = (string) $position->getVersion();
        $formToken = $this->client->getCrawler()->filter('input[name="position[_token]"]')->attr('value');
        $position->update('Newer title', '', PositionAccessType::RESTRICTED, 0);
        $this->em->flush();
        $this->client->request('POST', '/positions/'.$position->getId().'/edit', ['position' => [
            'title' => 'Stale title', 'shortDescription' => '', 'accessType' => 'public', 'maxProjects' => '0',
            'version' => $oldVersion, '_token' => $formToken,
        ]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Newer title', $this->em->getConnection()->fetchOne('SELECT title FROM position WHERE id = ?', [$position->getId()]));
        $token = $this->csrfToken('position_child_'.$position->getId());
        $this->client->request('POST', '/positions/'.$position->getId().'/attributes/add', [
            '_token' => $token, 'version' => $oldVersion, 'definition' => (string) $template->getId(), 'sortOrder' => '10',
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position_attribute WHERE position_id = ?', [$position->getId()]));

        $this->client->request('GET', '/positions/'.$position->getId().'/rules/new?definition='.$boolean->getId());
        self::assertResponseIsSuccessful();
        $ruleToken = $this->client->getCrawler()->filter('input[name="position_access_rule[_token]"]')->attr('value');
        $this->client->request('POST', '/positions/'.$position->getId().'/rules/new?definition='.$boolean->getId(), ['position_access_rule' => [
            'operator' => AccessRuleOperator::GREATER_THAN->value, 'booleanValue' => '1',
            'version' => (string) $this->em->getConnection()->fetchOne('SELECT version FROM position WHERE id = ?', [$position->getId()]), '_token' => $ruleToken,
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position_access_rule WHERE position_id = ?', [$position->getId()]));
        $this->client->request('POST', '/positions/'.$position->getId().'/rules/new?definition='.$image->getId(), []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAttributeTypeIsImmutableWhenUsedByTemplateOrRule(): void
    {
        $template = $this->definition('Used template', AttributeType::STRING);
        $ruleDefinition = $this->definition('Used rule', AttributeType::NUMERIC);
        $position = $this->position('Usage role', PositionAccessType::RESTRICTED);
        $position->addAttribute($template, 10);
        (new PositionAccessRule($position, $ruleDefinition, AccessRuleOperator::EQUAL))->setNumericExpected('1');
        $this->em->flush();
        $repository = static::getContainer()->get(AttributeDefinitionRepository::class);
        self::assertTrue($repository->isUsed($template));
        self::assertTrue($repository->isUsed($ruleDefinition));
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/attributes/'.$template->getId().'/edit');
        self::assertSelectorExists('select[name="attribute_definition[type]"][disabled]');
    }

    #[DataProvider('missingRequiredPositionFields')]
    public function testMissingRequiredPositionFieldCannotCreateOrUpdate(bool $editing, string $field, string $message): void
    {
        $position = $editing ? $this->position('Unchanged role', PositionAccessType::PUBLIC) : null;
        $this->em->flush();
        $this->client->loginUser($this->recruiter);
        $path = $position === null ? '/positions/new' : '/positions/'.$position->getId().'/edit';
        $this->client->request('GET', $path);
        $data = $this->client->getCrawler()->selectButton('Save position')->form()->getPhpValues()['position'];
        $data = array_replace($data, ['title' => 'Rejected role', 'accessType' => 'public', 'maxProjects' => '0']);
        unset($data[$field]);

        $this->client->request('POST', $path, ['position' => $data]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $message);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position WHERE title = ?', ['Rejected role']));
        if ($position !== null) {
            self::assertSame($position->getTitle(), $this->em->getConnection()->fetchOne('SELECT title FROM position WHERE id = ?', [$position->getId()]));
        }
    }

    public static function missingRequiredPositionFields(): iterable
    {
        foreach (['create' => false, 'edit' => true] as $operation => $editing) {
            yield $operation.' access type' => [$editing, 'accessType', 'Choose an access type.'];
            yield $operation.' project limit' => [$editing, 'maxProjects', 'Enter a maximum number of projects.'];
        }
    }

    public function testMissingAccessRuleOperatorIsRejectedBeforeSaving(): void
    {
        $position = $this->position('Unchanged rules', PositionAccessType::RESTRICTED);
        $definition = $this->definition('Required operator', AttributeType::STRING);
        $this->em->flush();
        $this->client->loginUser($this->recruiter);
        $path = '/positions/'.$position->getId().'/rules/new?definition='.$definition->getId();
        $this->client->request('GET', $path);
        $data = $this->client->getCrawler()->filter('main form')->form()->getPhpValues()['position_access_rule'];
        $data['textValue'] = 'Expected';
        unset($data['operator']);

        $this->client->request('POST', $path, ['position_access_rule' => $data]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Choose an operator.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position_access_rule WHERE position_id = ?', [$position->getId()]));
    }

    public function testProjectLimitMustFitDatabaseIntegerRange(): void
    {
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/positions/new');
        $data = $this->client->getCrawler()->selectButton('Save position')->form()->getPhpValues()['position'];
        $data = array_replace($data, ['title' => 'Rejected large limit', 'accessType' => 'public', 'maxProjects' => '2147483648']);
        $this->client->request('POST', '/positions/new', ['position' => $data]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Maximum projects is too large.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position WHERE title = ?', ['Rejected large limit']));
    }

    public function testAttributeOrderMustFitDatabaseIntegerRangeBeforeAddOrMove(): void
    {
        $position = $this->position('Order range', PositionAccessType::PUBLIC);
        $definition = $this->definition('New order', AttributeType::STRING);
        $existing = $position->addAttribute($this->definition('Existing order', AttributeType::STRING), 10);
        $this->em->flush();
        $this->client->loginUser($this->recruiter);
        $data = ['_token' => $this->csrfToken('position_child_'.$position->getId()), 'version' => (string) $position->getVersion(),
            'definition' => (string) $definition->getId(), 'sortOrder' => '2147483648'];

        $this->client->request('POST', '/positions/'.$position->getId().'/attributes/add', $data);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/positions/'.$position->getId().'/attributes/'.$existing->getId().'/order', $data);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(10, $this->em->getConnection()->fetchOne('SELECT sort_order FROM position_attribute WHERE id = ?', [$existing->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM position_attribute WHERE position_id = ?', [$position->getId()]));
    }

    private function position(string $title, PositionAccessType $access): Position
    {
        $position = new Position($title.' '.bin2hex(random_bytes(3)), '', $access, 0);
        $this->em->persist($position);
        return $position;
    }

    private function definition(string $name, AttributeType $type): AttributeDefinition
    {
        $definition = new AttributeDefinition($this->category, $name.' '.bin2hex(random_bytes(3)), $type);
        $this->em->persist($definition);
        return $definition;
    }

    private function csrfToken(string $id): string
    {
        $this->client->request('GET', '/positions');
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
