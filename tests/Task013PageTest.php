<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeDefinition;
use App\Entity\AttributeOption;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Task013PageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $candidate;
    private User $recruiter;
    private User $admin;
    private Profile $profile;
    private Position $publicPosition;
    private AttributeDefinition $firstName;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $suffix = bin2hex(random_bytes(4));
        $this->candidate = new User('task013-candidate-'.$suffix.'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->recruiter = new User('task013-recruiter-'.$suffix.'@example.test');
        $this->recruiter->setRoles(['ROLE_RECRUITER']);
        $this->admin = new User('task013-admin-'.$suffix.'@example.test');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $this->firstName = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $this->profile = Profile::createWithBuiltIns($this->candidate, $builtIns);
        foreach ($this->profile->getValues() as $value) {
            $text = match ($value->getDefinition()->getNormalizedName()) {
                'first name' => 'Ada',
                'last name' => 'Lovelace',
                'location' => 'London',
                default => null,
            };
            if ($text !== null) {
                $value->setText($text);
            }
        }
        $this->publicPosition = new Position('Quantum Zebra Engineer', 'A searchable public role', PositionAccessType::PUBLIC, 2);
        foreach ([$this->candidate, $this->recruiter, $this->admin, $this->profile, $this->publicPosition] as $entity) {
            $this->em->persist($entity);
        }
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

    public function testHeaderSearchAndAnonymousResultsExposeOnlyPublicPositions(): void
    {
        $hidden = $this->restrictedPosition('Quantum Zebra Secret', 'Other');
        foreach (['/', '/login', '/positions'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('header form[action="/search"][method="get"] input[name="q"]');
        }

        $this->client->request('GET', '/search?q=Quantum');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/positions/'.$this->publicPosition->getId().'"]');
        self::assertSelectorNotExists('a[href="/positions/'.$hidden->getId().'"]');
        self::assertSelectorTextNotContains('body', 'Quantum Zebra Secret');

        foreach (['/search', '/search?q=%22unclosed', '/search?q=-Quantum'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
        self::assertSelectorNotExists('a[href="/positions/'.$this->publicPosition->getId().'"]');
        $this->client->request('GET', '/search?q='.str_repeat('x', 201));
        self::assertResponseStatusCodeSame(422);
    }

    public function testCandidateSearchUsesCurrentPositionEligibility(): void
    {
        $eligible = $this->restrictedPosition('Celestial Architect', 'Ada');
        $hidden = $this->restrictedPosition('Celestial Secret', 'Other');
        $this->client->loginUser($this->candidate);
        $this->client->request('GET', '/search?q=Celestial');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/positions/'.$eligible->getId().'"]');
        self::assertSelectorNotExists('a[href="/positions/'.$hidden->getId().'"]');
        self::assertSelectorTextNotContains('body', 'Celestial Secret');
    }

    public function testRecruiterCVSearchUsesRenderedMasterTextAndBulkLikeCounts(): void
    {
        $this->textAttribute('Technical Summary', $this->publicPosition, 'Masterquasar PHP experience');
        $cv = $this->cv($this->publicPosition, true);
        $this->em->getConnection()->insert('cv_like', ['cv_id' => $cv->getId(), 'recruiter_user_id' => $this->recruiter->getId(), 'created_at' => '2026-09-23 00:00:00']);
        $select = new AttributeDefinition($this->firstName->getCategory(), 'Primary Framework', AttributeType::SELECT);
        $option = new AttributeOption($select, 'Orbitframework', 0);
        $this->em->persist($select);
        $this->publicPosition->addAttribute($select, 1);
        $this->profile->selectAttribute($select)->setOption($option);
        $this->em->flush();
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/search?q=Masterquasar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/cvs/'.$cv->getId().'"]');
        self::assertSelectorTextContains('#cv-results', 'Ada Lovelace');
        self::assertSelectorTextContains('#cv-results', '1');
        $this->client->request('GET', '/search?q=Quantum%20Ada');
        self::assertSelectorExists('a[href="/cvs/'.$cv->getId().'"]');

        $this->client->request('GET', '/search?q=Orbitframework');
        self::assertSelectorExists('a[href="/cvs/'.$cv->getId().'"]');
        $this->client->request('GET', '/search?q=-Masterquasar');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/cvs/'.$cv->getId().'"]');
    }

    public function testCVSearchIgnoresUnrenderedValuesAndTracksMasterEdits(): void
    {
        $private = new AttributeDefinition($this->firstName->getCategory(), 'Private Signal', AttributeType::TEXT);
        $this->em->persist($private);
        $this->profile->selectAttribute($private)->setText('Secretnebula');
        $value = $this->textAttribute('Public Signal', $this->publicPosition, 'Oldstar');
        $cv = $this->cv($this->publicPosition, true);
        $this->client->loginUser($this->recruiter);

        $this->client->request('GET', '/search?q=Secretnebula');
        self::assertSelectorNotExists('a[href="/cvs/'.$cv->getId().'"]');
        $this->client->request('GET', '/search?q=Oldstar');
        self::assertSelectorExists('a[href="/cvs/'.$cv->getId().'"]');

        $managedValue = $this->em->find(\App\Entity\ProfileAttributeValue::class, $value->getId());
        $managedValue->setText('Newstar');
        $this->em->flush();
        self::assertSame('Newstar', $this->em->getConnection()->fetchOne('SELECT text_value FROM profile_attribute_value WHERE id = ?', [$value->getId()]));
        $this->client->request('GET', '/search?q=Oldstar');
        self::assertSelectorNotExists('a[href="/cvs/'.$cv->getId().'"]');
        $this->client->request('GET', '/search?q=Newstar');
        self::assertSelectorExists('a[href="/cvs/'.$cv->getId().'"]');
    }

    public function testAdminSearchSeesDraftAndIneligibleCVsThatRecruiterCannot(): void
    {
        $draft = $this->cv($this->publicPosition, false);
        $hiddenPosition = $this->restrictedPosition('Hidden Ada Position', 'Other');
        $hidden = $this->cv($hiddenPosition, true);
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/search?q=Ada');
        self::assertSelectorNotExists('a[href="/cvs/'.$draft->getId().'"]');
        self::assertSelectorNotExists('a[href="/cvs/'.$hidden->getId().'"]');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/search?q=Ada');
        self::assertSelectorExists('a[href="/cvs/'.$draft->getId().'"]');
        self::assertSelectorExists('a[href="/cvs/'.$hidden->getId().'"]');
        self::assertSelectorTextContains('#cv-results', 'Draft');
    }

    public function testDashboardLatestPopularAndPublicStatistics(): void
    {
        $newer = new Position('Latest Position', 'Fresh role', PositionAccessType::PUBLIC, 0);
        $this->em->persist($newer);
        $second = $this->additionalCandidate();
        $this->cv($this->publicPosition, true);
        $secondCV = new CV($second, $this->publicPosition);
        $secondCV->publish();
        $this->em->persist($secondCV);
        $this->em->flush();
        $this->cv($newer, true);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#latest-positions tbody tr:first-child', 'Latest Position');
        self::assertSelectorTextContains('#popular-positions tbody tr:first-child', 'Quantum Zebra Engineer');
        self::assertSelectorTextContains('#popular-positions tbody tr:first-child', '2');
        $connection = $this->em->getConnection();
        self::assertSelectorTextContains('#stat-total-positions', (string) $connection->fetchOne('SELECT COUNT(*) FROM position'));
        self::assertSelectorTextContains('#stat-submitted-cvs', (string) $connection->fetchOne("SELECT COUNT(*) FROM cv WHERE status = 'PUBLISHED'"));
        self::assertSelectorTextContains('#stat-cvs-24h', (string) $connection->fetchOne("SELECT COUNT(*) FROM cv WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours'"));
        self::assertSelectorTextContains('#stat-candidates', '2');
        self::assertSelectorTextContains('#stat-recruiters', '1');
    }

    public function testTagCloudAndLandingRespectEachRoleVisibility(): void
    {
        $hidden = $this->restrictedPosition('Tagged Hidden Position', 'Other');
        $tag = new Tag('Kubernetes');
        $this->em->persist($tag);
        $this->publicPosition->addProjectTag($tag);
        $hidden->addProjectTag($tag);
        $this->em->flush();
        $publicCV = $this->cv($this->publicPosition, true);
        $hiddenCV = $this->cv($hidden, true);

        $this->client->request('GET', '/');
        self::assertSelectorExists('a[href="/tags/'.$tag->getId().'"][data-weight="1"]');
        self::assertSelectorTextNotContains('main', 'Tagged Hidden Position');
        $this->client->request('GET', '/tags/'.$tag->getId());
        self::assertSelectorExists('a[href="/positions/'.$this->publicPosition->getId().'"]');
        self::assertSelectorNotExists('a[href="/positions/'.$hidden->getId().'"]');

        $this->client->loginUser($this->candidate);
        $this->em->getConnection()->executeStatement("INSERT INTO position (title, short_description, access_type, max_projects, version, created_at, updated_at) SELECT 'Cloud noise ' || n, '', 'public', 0, 1, CURRENT_TIMESTAMP + INTERVAL '1 day', CURRENT_TIMESTAMP + INTERVAL '1 day' FROM generate_series(1, 501) AS n");
        $this->client->request('GET', '/');
        self::assertSelectorExists('a[href="/tags/'.$tag->getId().'"][data-weight="1"]');
        self::assertSelectorTextNotContains('main', 'Tagged Hidden Position');
        $this->client->request('GET', '/tags/'.$tag->getId());
        self::assertSelectorExists('a[href="/positions/'.$this->publicPosition->getId().'"]');
        self::assertSelectorNotExists('a[href="/positions/'.$hidden->getId().'"]');

        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/');
        self::assertSelectorExists('a[href="/tags/'.$tag->getId().'"][data-weight="2"]');
        $this->client->request('GET', '/tags/'.$tag->getId());
        self::assertSelectorExists('a[href="/cvs/'.$publicCV->getId().'"]');
        self::assertSelectorNotExists('a[href="/cvs/'.$hiddenCV->getId().'"]');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/tags/'.$tag->getId());
        self::assertSelectorExists('a[href="/cvs/'.$publicCV->getId().'"]');
        self::assertSelectorExists('a[href="/cvs/'.$hiddenCV->getId().'"]');
    }

    public function testPostgreSQLHasGINIndexesForAllSearchSources(): void
    {
        $indexes = $this->em->getConnection()->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname IN ('idx_position_fts', 'idx_profile_attribute_value_fts', 'idx_attribute_option_fts')");
        self::assertCount(3, $indexes);
        foreach ($indexes as $definition) {
            self::assertStringContainsString('using gin', strtolower($definition));
            self::assertStringContainsString('to_tsvector', $definition);
            self::assertStringContainsString('simple', $definition);
        }
        self::assertTrue((bool) $this->em->getConnection()->fetchOne("SELECT to_tsvector('simple'::regconfig, 'Quantum Zebra') @@ websearch_to_tsquery('simple'::regconfig, 'Quantum')"));
    }

    private function restrictedPosition(string $title, string $requiredName): Position
    {
        $position = new Position($title, 'Restricted role', PositionAccessType::RESTRICTED, 1);
        (new PositionAccessRule($position, $this->firstName, AccessRuleOperator::EQUAL))->setTextExpected($requiredName);
        $this->em->persist($position);
        $this->em->flush();
        return $position;
    }

    private function textAttribute(string $name, Position $position, string $text): \App\Entity\ProfileAttributeValue
    {
        $definition = new AttributeDefinition($this->firstName->getCategory(), $name, AttributeType::TEXT);
        $this->em->persist($definition);
        $position->addAttribute($definition, 0);
        $value = $this->profile->selectAttribute($definition);
        $value->setText($text);
        $this->em->flush();
        return $value;
    }

    private function cv(Position $position, bool $published): CV
    {
        $cv = new CV($this->profile, $position);
        if ($published) {
            $cv->publish();
        }
        $this->em->persist($cv);
        $this->em->flush();
        return $cv;
    }

    private function additionalCandidate(): Profile
    {
        $user = new User('task013-second-'.bin2hex(random_bytes(4)).'@example.test');
        $user->setRoles(['ROLE_CANDIDATE']);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $profile = Profile::createWithBuiltIns($user, $builtIns);
        $this->em->persist($user);
        $this->em->persist($profile);
        $this->em->flush();
        return $profile;
    }
}
