<?php

declare(strict_types=1);

namespace App\Tests\CV;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\CVStatus;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CVPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $candidate;
    private Profile $profile;
    private AttributeCategory $category;
    /** @var array<int, string> */
    private array $createTokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->category = new AttributeCategory('CV '.bin2hex(random_bytes(4)));
        $this->candidate = new User('cv-'.bin2hex(random_bytes(5)).'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $this->profile = Profile::createWithBuiltIns($this->candidate, $builtIns);
        $this->em->persist($this->category);
        $this->em->persist($this->candidate);
        $this->em->persist($this->profile);
        $this->em->flush();
        $this->client->loginUser($this->candidate);
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

    public function testCreateReusesOneDraftAndMaterializesOnlyMissingMasterRows(): void
    {
        $language = $this->definition('Language', AttributeType::STRING);
        $position = $this->position('Developer');
        $position->addAttribute($language, 0);
        $this->em->flush();

        $this->createCv($position);
        self::assertResponseRedirects();
        $cv = $this->em->getRepository(CV::class)->findOneBy(['profile' => $this->profile, 'position' => $position]);
        self::assertNotNull($cv);
        self::assertSame(CVStatus::DRAFT, $cv->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $language->getId()]));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT text_value FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $language->getId()]));
        $this->createCv($position);
        self::assertResponseRedirects('/cvs/'.$cv->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE profile_id = ? AND position_id = ?', [$this->profile->getId(), $position->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $language->getId()]));
        $this->em->getConnection()->delete('profile', ['id' => $this->profile->getId()]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$cv->getId()]));
    }

    public function testCurrentTemplateAndMasterValueAppearAndAutosaveRejectsStaleVersion(): void
    {
        $position = $this->position('Developer');
        $language = $this->definition('English Level', AttributeType::STRING);
        $this->em->flush();
        $cv = $this->createCv($position);
        $this->addPositionAttribute($position, $language);
        $this->client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'English Level');
        self::assertSelectorExists('.border-danger');
        $crawler = $this->client->getCrawler();
        $token = $crawler->filter('[data-profile-autosave-token-value]')->attr('data-profile-autosave-token-value');
        $version = (int) $crawler->filter('[data-profile-autosave-version-value]')->attr('data-profile-autosave-version-value');
        $this->client->jsonRequest('POST', '/cvs/'.$cv->getId().'/autosave', ['version' => $version, 'changes' => [(string) $language->getId() => 'C1']], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame('C1', $this->storedText($language));
        $this->client->jsonRequest('POST', '/cvs/'.$cv->getId().'/autosave', ['version' => $version, 'changes' => [(string) $language->getId() => 'Stale']], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('C1', $this->storedText($language));
        $this->em->getConnection()->delete('position', ['id' => $position->getId()]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$cv->getId()]));
    }

    public function testPublishRequiresEveryCurrentFieldAndRecruiterGetsReadOnlyPublishedView(): void
    {
        $position = $this->position('Developer');
        $newRequirement = $this->definition('New requirement', AttributeType::TEXT);
        $this->em->flush();
        $cv = $this->createCv($position);
        $this->client->request('GET', '/cvs/'.$cv->getId());
        $token = $this->client->getCrawler()->filter('form[action="/cvs/'.$cv->getId().'/publish"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/cvs/'.$cv->getId().'/publish', ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        $this->completeBuiltIns();
        $this->client->request('POST', '/cvs/'.$cv->getId().'/publish', ['_token' => $token]);
        self::assertResponseRedirects('/cvs/'.$cv->getId());
        self::assertSame(CVStatus::PUBLISHED->value, $this->em->getConnection()->fetchOne('SELECT status FROM cv WHERE id = ?', [$cv->getId()]));
        $this->writeBuiltIn('first name', 'text_value', 'Grace');
        $this->addPositionAttribute($position, $newRequirement);
        $recruiter = new User('cv-recruiter-'.bin2hex(random_bytes(5)).'@example.test');
        $recruiter->setRoles(['ROLE_RECRUITER']);
        $this->em->persist($recruiter);
        $this->em->flush();
        $this->client->loginUser($recruiter);
        $this->client->request('GET', '/positions/'.$position->getId().'/cvs');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
        $this->client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Grace');
        self::assertSelectorTextContains('body', 'New requirement');
        self::assertSelectorExists('.border-danger');
        self::assertSelectorNotExists('[data-profile-autosave-url-value]');
        $this->client->request('POST', '/cvs/'.$cv->getId().'/publish', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEligibilityLossHidesPersistedCvAndProjectsUseAnyTagAndRecentLimit(): void
    {
        $score = $this->definition('Score', AttributeType::NUMERIC);
        $this->profile->selectAttribute($score)->setNumeric('8');
        $position = $this->position('Private', PositionAccessType::RESTRICTED, 2);
        (new PositionAccessRule($position, $score, AccessRuleOperator::GREATER_THAN))->setNumericExpected('7');
        $matching = new Tag('CV matching '.bin2hex(random_bytes(3)));
        $other = new Tag('CV other '.bin2hex(random_bytes(3)));
        $position->addProjectTag($matching);
        $this->em->persist($matching);
        $this->em->persist($other);
        $this->project('Old match', '2024-01-01', $matching);
        $this->project('Recent match', '2026-01-01', $matching);
        $this->project('Newest match', '2026-06-01', $matching);
        $this->project('Unrelated', '2026-09-01', $other);
        $this->em->flush();
        $cv = $this->createCv($position);
        $this->client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Newest match');
        self::assertSelectorTextContains('body', 'Recent match');
        self::assertSelectorTextNotContains('body', 'Old match');
        self::assertSelectorTextNotContains('body', 'Unrelated');
        $this->completeBuiltIns();
        $this->client->request('GET', '/cvs/'.$cv->getId());
        $publishToken = $this->client->getCrawler()->filter('form[action="/cvs/'.$cv->getId().'/publish"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/cvs/'.$cv->getId().'/publish', ['_token' => $publishToken]);
        self::assertResponseRedirects('/cvs/'.$cv->getId());
        $this->em->getConnection()->executeStatement('UPDATE profile_attribute_value SET numeric_value = ? WHERE profile_id = ? AND attribute_definition_id = ?', ['6', $this->profile->getId(), $score->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE profile SET version = version + 1 WHERE id = ?', [$this->profile->getId()]);
        $this->client->request('GET', '/cvs');
        self::assertSelectorTextNotContains('body', $position->getTitle());
        $this->client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseStatusCodeSame(403);
        $recruiter = new User('cv-recruiter-'.bin2hex(random_bytes(5)).'@example.test');
        $recruiter->setRoles(['ROLE_RECRUITER']);
        $this->em->persist($recruiter);
        $this->em->flush();
        $this->client->loginUser($recruiter);
        $this->client->request('GET', '/positions/'.$position->getId().'/cvs');
        self::assertSelectorTextNotContains('body', $this->candidate->getEmail());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$cv->getId()]));
        $this->client->loginUser($this->candidate);
        $this->client->request('POST', '/positions/'.$position->getId().'/cv', ['_token' => $this->createTokens[$position->getId()]]);
        self::assertResponseStatusCodeSame(403);
    }

    private function definition(string $name, AttributeType $type): AttributeDefinition
    {
        $definition = new AttributeDefinition($this->category, $name.' '.bin2hex(random_bytes(3)), $type);
        $this->em->persist($definition);
        return $definition;
    }

    private function position(string $title, PositionAccessType $access = PositionAccessType::PUBLIC, int $maxProjects = 0): Position
    {
        $position = new Position($title.' '.bin2hex(random_bytes(3)), '', $access, $maxProjects);
        $this->em->persist($position);
        return $position;
    }

    private function createCv(Position $position): CV
    {
        $this->client->request('GET', '/positions/'.$position->getId());
        $field = $this->client->getCrawler()->filter('form[action="/positions/'.$position->getId().'/cv"] input[name="_token"]');
        if ($field->count() > 0) {
            $this->createTokens[$position->getId()] = $field->attr('value');
        }
        $token = $this->createTokens[$position->getId()] ?? null;
        $this->client->request('POST', '/positions/'.$position->getId().'/cv', ['_token' => $token]);
        return $this->em->getRepository(CV::class)->findOneBy(['profile' => $this->profile, 'position' => $position]);
    }

    private function completeBuiltIns(): void
    {
        foreach (['first name' => 'Ada', 'last name' => 'Lovelace', 'location' => 'London'] as $name => $text) {
            $this->writeBuiltIn($name, 'text_value', $text);
        }
        $this->writeBuiltIn('personal photo', 'image_key', 'fixture/photo');
    }

    private function writeBuiltIn(string $name, string $column, string $value): void
    {
        $definitionId = $this->em->getConnection()->fetchOne('SELECT id FROM attribute_definition WHERE normalized_name = ?', [$name]);
        $this->em->getConnection()->executeStatement('UPDATE profile_attribute_value SET '.$column.' = ? WHERE profile_id = ? AND attribute_definition_id = ?', [$value, $this->profile->getId(), $definitionId]);
        $this->em->getConnection()->executeStatement('UPDATE profile SET version = version + 1 WHERE id = ?', [$this->profile->getId()]);
    }

    private function addPositionAttribute(Position $position, AttributeDefinition $definition): void
    {
        $this->em->getConnection()->insert('position_attribute', [
            'position_id' => $position->getId(), 'attribute_definition_id' => $definition->getId(), 'sort_order' => 0,
        ]);
        $this->em->getConnection()->executeStatement('UPDATE position SET version = version + 1 WHERE id = ?', [$position->getId()]);
    }

    private function storedText(AttributeDefinition $definition): ?string
    {
        $text = $this->em->getConnection()->fetchOne('SELECT text_value FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $definition->getId()]);
        return $text === false ? null : $text;
    }

    private function project(string $name, string $end, Tag $tag): void
    {
        $project = new Project($this->profile, $name, new \DateTimeImmutable('2023-01-01'), new \DateTimeImmutable($end), 'Project description');
        $project->replaceTags([$tag]);
        $this->em->persist($project);
    }
}
