<?php

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProjectPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $candidate;
    private Profile $profile;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->candidate = new User('projects-'.bin2hex(random_bytes(5)).'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->em->persist($this->candidate);
        $this->profile = Profile::createWithBuiltIns($this->candidate, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
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

    public function testCandidateCreatesAndListsProjectWithEscapedMarkdownAndTags(): void
    {
        $this->submitProject('/profile/projects/new', 'Portfolio', '2025-01-01', '', '<script>alert(1)</script> **work**', 'Symfony, PostgreSQL');
        self::assertResponseRedirects('/profile/projects');

        $this->client->request('GET', '/profile/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Portfolio');
        self::assertSelectorTextContains('main', 'Symfony');
        self::assertSelectorTextContains('main', 'PostgreSQL');
        self::assertSelectorNotExists('main script');
        self::assertSelectorExists('nav a[href="/profile/projects"]');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project WHERE profile_id = ?', [$this->profile->getId()]));
    }

    public function testCandidateEditsAndDeletesOwnProjectWithoutDeletingGlobalTag(): void
    {
        $id = $this->createProject('First', 'Symfony');
        $this->submitProject('/profile/projects/'.$id.'/edit', 'Revised', '2025-01-01', '2025-07-01', 'Done', 'Symfony, PHP');
        self::assertResponseRedirects('/profile/projects');
        self::assertSame('Revised', $this->em->getConnection()->fetchOne('SELECT name FROM candidate_project WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project_tag WHERE project_id = ?', [$id]));

        $this->client->request('GET', '/profile/projects');
        $token = $this->client->getCrawler()->filter('form[action="/profile/projects/'.$id.'/delete"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/profile/projects/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/profile/projects');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project_tag WHERE project_id = ?', [$id]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM tag WHERE normalized_name = 'symfony'"));
    }

    public function testCandidateCannotEditOrDeleteAnotherProfilesProject(): void
    {
        $id = $this->createProject('Private', 'PHP');
        $other = new User('other-'.bin2hex(random_bytes(5)).'@example.test');
        $other->setRoles(['ROLE_CANDIDATE']);
        $this->em->persist($other);
        $this->em->persist(Profile::createWithBuiltIns($other, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true])));
        $this->em->flush();
        $this->client->loginUser($other);

        $this->client->request('GET', '/profile/projects/'.$id.'/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/profile/projects/'.$id.'/delete', ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Private', $this->em->getConnection()->fetchOne('SELECT name FROM candidate_project WHERE id = ?', [$id]));
    }

    public function testAnonymousAndRecruiterCannotUseProjectRoutes(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/profile/projects');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/profile/tags/search?q=Sy');
        self::assertResponseRedirects('/login');

        $recruiter = new User('recruiter-'.bin2hex(random_bytes(5)).'@example.test');
        $recruiter->setRoles(['ROLE_RECRUITER']);
        $this->em->persist($recruiter);
        $this->em->flush();
        $this->client->loginUser($recruiter);
        $this->client->request('GET', '/profile/projects/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/profile/tags/search?q=Sy');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidNamePeriodAndMalformedTagsAreRejected(): void
    {
        $this->submitProject('/profile/projects/new', ' ', '2025-01-01', '', 'Invalid', 'PHP');
        self::assertResponseStatusCodeSame(422);
        $this->submitProject('/profile/projects/new', 'Invalid period', '2025-08-01', '2025-01-01', 'Invalid', 'PHP');
        self::assertResponseStatusCodeSame(422);
        $this->submitProject('/profile/projects/new', 'Invalid tags', '2025-01-01', '', 'Invalid', 'PHP,,Symfony');
        self::assertResponseStatusCodeSame(422);
        $this->submitProject('/profile/projects/new', 'Too many tags', '2025-01-01', '', 'Invalid', implode(', ', array_fill(0, 51, 'PHP')));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project WHERE profile_id = ?', [$this->profile->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM tag WHERE normalized_name IN ('php', 'symfony')"));
    }

    public function testProjectTagLengthLimitIsEnforcedBeforeCreatingOrEditing(): void
    {
        $tags = implode(',', array_fill(0, 25, str_repeat('a', 100)));
        $this->submitProject('/profile/projects/new', 'Rejected', '2025-01-01', '', '', $tags);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Technology tags must be at most 2500 characters.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project WHERE profile_id = ?', [$this->profile->getId()]));

        $id = $this->createProject('Unchanged', 'PHP');
        $this->submitProject('/profile/projects/'.$id.'/edit', 'Rejected', '2025-01-01', '', '', $tags);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Technology tags must be at most 2500 characters.');
        self::assertSame('Unchanged', $this->em->getConnection()->fetchOne('SELECT name FROM candidate_project WHERE id = ?', [$id]));
    }

    public function testTagsAreReusedAndCaseInsensitiveDuplicatesCollapse(): void
    {
        $first = $this->createProject('First', 'Symfony, symfony, SYMFONY');
        $second = $this->createProject('Second', 'symfony, ');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM tag WHERE normalized_name = 'symfony'"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project_tag WHERE project_id = ?', [$first]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project_tag WHERE project_id = ?', [$second]));
    }

    public function testTagAutocompleteUsesPrefixAndLimitsResults(): void
    {
        $tags = ['Symfony', 'Svelte', 'PHP'];
        for ($index = 0; $index < 17; $index++) {
            $tags[] = 'Stack'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        }
        $this->createProject('Tag seed', implode(', ', $tags));
        $this->client->request('GET', '/profile/tags/search?q=ST');
        self::assertResponseIsSuccessful();
        $names = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(15, $names);
        self::assertSame('Stack00', $names[0]);
        self::assertNotContains('PHP', $names);
    }

    public function testInvalidCsrfCannotCreateOrDeleteProject(): void
    {
        $id = $this->createProject('Keep', 'PHP');
        $this->client->request('POST', '/profile/projects/new', ['project' => ['name' => 'Blocked', 'startDate' => '2025-01-01', 'endDate' => '', 'description' => '', 'tags' => 'PHP', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/profile/projects/'.$id.'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project WHERE profile_id = ?', [$this->profile->getId()]));
    }

    public function testDeletingProfileCascadesProjectsAndJoinRowsButKeepsTags(): void
    {
        $id = $this->createProject('Cascading', 'Persistent');
        $this->em->getConnection()->executeStatement('DELETE FROM profile WHERE id = ?', [$this->profile->getId()]);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM candidate_project_tag WHERE project_id = ?', [$id]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM tag WHERE normalized_name = 'persistent'"));
    }

    private function createProject(string $name, string $tags): int
    {
        $this->submitProject('/profile/projects/new', $name, '2025-01-01', '', 'Description', $tags);
        self::assertResponseRedirects('/profile/projects');
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM candidate_project WHERE profile_id = ? AND name = ?', [$this->profile->getId(), $name]);
    }

    private function submitProject(string $path, string $name, string $start, string $end, string $description, string $tags): void
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('input[name="project[_token]"]')->attr('value');
        $this->client->request('POST', $path, ['project' => [
            'name' => $name,
            'startDate' => $start,
            'endDate' => $end,
            'description' => $description,
            'tags' => $tags,
            '_token' => $token,
        ]]);
    }
}
