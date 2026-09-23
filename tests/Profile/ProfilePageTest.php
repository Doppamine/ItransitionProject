<?php

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfilePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private Profile $profile;
    private string $csrfToken;

    protected function setUp(): void
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
        if (parse_url($databaseUrl, PHP_URL_HOST) === 'HOST') {
            self::markTestSkipped('Neon development DATABASE_URL is required for Profile integration tests.');
        }

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->user = new User('profile-page-'.bin2hex(random_bytes(5)).'@example.test');
        $this->user->setRoles(['ROLE_CANDIDATE']);
        $this->entityManager->persist($this->user);
        $builtIns = $this->entityManager->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $this->profile = Profile::createWithBuiltIns($this->user, $builtIns);
        $this->entityManager->persist($this->profile);
        $this->entityManager->flush();
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/profile');
        $this->csrfToken = $page->filter('[data-profile-autosave-token-value]')->attr('data-profile-autosave-token-value');
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->clear();
        }
        parent::tearDown();
    }

    public function testCandidateSeesBuiltInsAndOptionalValue(): void
    {
        $optional = $this->definition('Language', AttributeType::STRING);
        $this->profile->selectAttribute($optional)->setText('English');
        $this->entityManager->flush();

        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        foreach (['First Name', 'Last Name', 'Location', 'Personal Photo', 'Language'] as $name) {
            self::assertSelectorTextContains('body', $name);
        }
        self::assertSelectorExists('input[value="English"]');
        self::assertSelectorExists('[data-controller="image-upload"] input[type="file"][accept*="image/jpeg"]');
        self::assertSelectorExists('nav a[href="/profile"]');
    }

    public function testSearchUsesPrefixCategoryAndExcludesSelectedDefinitions(): void
    {
        $skills = new AttributeCategory('Skills');
        $other = new AttributeCategory('Interests');
        $this->entityManager->persist($skills);
        $this->entityManager->persist($other);
        $language = new AttributeDefinition($skills, 'Language', AttributeType::STRING);
        $landscape = new AttributeDefinition($other, 'Landscape', AttributeType::STRING);
        $coding = new AttributeDefinition($skills, 'Coding', AttributeType::STRING);
        foreach ([$language, $landscape, $coding] as $definition) {
            $this->entityManager->persist($definition);
        }
        $this->profile->selectAttribute($landscape);
        $this->entityManager->flush();

        $this->client->request('GET', '/profile?q=lan&category='.$skills->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#attribute-results', 'Language');
        self::assertSelectorTextNotContains('#attribute-results', 'Landscape');
        self::assertSelectorTextNotContains('#attribute-results', 'Coding');
    }

    public function testOptionalSelectionIsIdempotentAndRemovalWorks(): void
    {
        $definition = $this->definition('Language', AttributeType::STRING);
        $path = '/profile/attributes/'.$definition->getId().'/select';
        $this->client->request('POST', $path, ['_token' => $this->token()]);
        self::assertResponseRedirects('/profile#info');
        $this->client->request('POST', $path, ['_token' => $this->token()]);
        self::assertResponseRedirects('/profile#info');
        self::assertSame(1, $this->storedCount($definition));

        $this->client->request('POST', '/profile/attributes/'.$definition->getId().'/remove', ['_token' => $this->token()]);
        self::assertResponseRedirects('/profile#info');
        self::assertSame(0, $this->storedCount($definition));
    }

    public function testBuiltInRemovalAndBadCsrfAreRejected(): void
    {
        $builtIn = $this->entityManager->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $this->client->request('POST', '/profile/attributes/'.$builtIn->getId().'/remove', ['_token' => $this->token()]);
        self::assertResponseStatusCodeSame(422);

        $optional = $this->definition('Language', AttributeType::STRING);
        $this->client->request('POST', '/profile/attributes/'.$optional->getId().'/select', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->profile->getValueFor($optional));
    }

    public function testAutosaveUpdatesOnlyDirtyValueAndReturnsNewVersion(): void
    {
        $first = $this->entityManager->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $last = $this->entityManager->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'last name']);
        $this->profile->getValueFor($last)->setText('Original');
        $this->entityManager->flush();
        $version = $this->profile->getVersion();

        $this->autosave($version, [(string) $first->getId() => 'Ada']);

        self::assertResponseIsSuccessful();
        self::assertSame('Ada', $this->storedText($first));
        self::assertSame('Original', $this->storedText($last));
        self::assertGreaterThan($version, json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['version']);
    }

    public function testInvalidPeriodAndWrongSelectOptionReturn422(): void
    {
        $period = $this->definition('Employment', AttributeType::PERIOD);
        $select = $this->definition('Language', AttributeType::SELECT);
        $other = $this->definition('Level', AttributeType::SELECT);
        $option = $other->addOption('Advanced', 0);
        $this->profile->selectAttribute($period);
        $this->profile->selectAttribute($select);
        $this->entityManager->flush();

        $this->autosave($this->profile->getVersion(), [(string) $period->getId() => ['start' => '2026-01-01', 'end' => '']]);
        self::assertResponseStatusCodeSame(422);
        $this->autosave($this->profile->getVersion(), [(string) $select->getId() => (string) $option->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testStaleVersionAndInvalidCsrfCannotOverwrite(): void
    {
        $first = $this->entityManager->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $value = $this->profile->getValueFor($first);
        $value->setText('Current');
        $this->entityManager->flush();

        $this->autosave($this->profile->getVersion() - 1, [(string) $first->getId() => 'Stale']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Current', $this->storedText($first));

        $this->autosave($this->profile->getVersion(), [(string) $first->getId() => 'Bad token'], 'invalid');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Current', $this->storedText($first));
    }

    public function testRecruiterCannotOpenOrMutateProfile(): void
    {
        $this->user->setRoles(['ROLE_RECRUITER']);
        $this->entityManager->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/profile');
        self::assertResponseStatusCodeSame(403);
        $this->autosave($this->profile->getVersion(), [], $this->token());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAutosaveRejectsUnselectedAttribute(): void
    {
        $definition = $this->definition('Unselected', AttributeType::STRING);

        $this->autosave($this->profile->getVersion(), [(string) $definition->getId() => 'No access']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedCount($definition));
    }

    public function testBooleanFalseAndClearingPersist(): void
    {
        $definition = $this->definition('Available', AttributeType::BOOLEAN);
        $this->profile->selectAttribute($definition);
        $this->entityManager->flush();

        $this->autosave($this->profile->getVersion(), [(string) $definition->getId() => 'false']);
        self::assertResponseIsSuccessful();
        $version = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['version'];
        self::assertFalse($this->entityManager->getConnection()->fetchOne('SELECT boolean_value FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $definition->getId()]));

        $this->autosave($version, [(string) $definition->getId() => '']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT boolean_value FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $definition->getId()]));
    }

    public function testSelectOptionFromSameDefinitionPersists(): void
    {
        $definition = $this->definition('Language', AttributeType::SELECT);
        $option = $definition->addOption('English', 0);
        $this->profile->selectAttribute($definition);
        $this->entityManager->flush();

        $this->autosave($this->profile->getVersion(), [(string) $definition->getId() => (string) $option->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame($option->getId(), (int) $this->entityManager->getConnection()->fetchOne('SELECT option_id FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $definition->getId()]));
    }

    public function testInvalidBatchDoesNotSaveEarlierValidField(): void
    {
        $first = $this->entityManager->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $period = $this->definition('Employment', AttributeType::PERIOD);
        $this->profile->selectAttribute($period);
        $this->entityManager->flush();

        $this->autosave($this->profile->getVersion(), [
            (string) $first->getId() => 'Should not save',
            (string) $period->getId() => ['start' => '2026-01-01', 'end' => ''],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->storedText($first));
    }

    private function definition(string $name, AttributeType $type): AttributeDefinition
    {
        $category = new AttributeCategory('Category '.bin2hex(random_bytes(4)));
        $this->entityManager->persist($category);
        $definition = new AttributeDefinition($category, $name.' '.bin2hex(random_bytes(3)), $type);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
        return $definition;
    }

    private function token(): string
    {
        return $this->csrfToken;
    }

    private function autosave(int $version, array $changes, ?string $token = null): void
    {
        $this->client->jsonRequest('POST', '/profile/autosave', ['version' => $version, 'changes' => $changes], ['HTTP_X_CSRF_TOKEN' => $token ?? $this->token()]);
    }

    private function storedCount(AttributeDefinition $definition): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $definition->getId()]);
    }

    private function storedText(AttributeDefinition $definition): ?string
    {
        $text = $this->entityManager->getConnection()->fetchOne('SELECT text_value FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $definition->getId()]);
        return $text === false ? null : $text;
    }
}
