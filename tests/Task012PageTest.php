<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\DiscussionPost;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\PositionAccessType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Task012PageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $candidate;
    private User $recruiter;
    private Profile $profile;
    private Position $position;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->candidate = new User('task012-candidate-'.bin2hex(random_bytes(4)).'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->recruiter = new User('task012-recruiter-'.bin2hex(random_bytes(4)).'@example.test');
        $this->recruiter->setRoles(['ROLE_RECRUITER']);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
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
        $this->position = new Position('Task 012 developer', 'A public role', PositionAccessType::PUBLIC, 2);
        foreach ([$this->candidate, $this->recruiter, $this->profile, $this->position] as $entity) {
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

    public function testDiscussionAccessAndPostingFollowCurrentPositionEligibility(): void
    {
        $this->client->loginUser($this->recruiter);
        $this->post($this->position, 'Recruiter announcement');
        self::assertResponseRedirects('/positions/'.$this->position->getId());
        $this->client->request('POST', '/positions/'.$this->position->getId().'/discussion', ['content' => 'No token']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/positions/'.$this->position->getId());
        $token = $this->client->getCrawler()->filter('form[action="/positions/'.$this->position->getId().'/discussion"] input[name="_token"]')->attr('value');
        foreach (['   ', str_repeat('x', 10001)] as $invalid) {
            $this->client->request('POST', '/positions/'.$this->position->getId().'/discussion', ['_token' => $token, 'content' => $invalid]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM discussion_post WHERE position_id = ?', [$this->position->getId()]));

        $this->client->restart();
        $this->client->request('GET', '/positions/'.$this->position->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Recruiter announcement');
        $this->client->request('GET', '/positions/'.$this->position->getId().'/discussion/posts?after=0');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/positions/'.$this->position->getId().'/discussion', ['content' => 'Nope']);
        self::assertResponseRedirects('/login');

        $hidden = $this->ineligiblePosition();
        $this->client->loginUser($this->candidate);
        $this->client->request('GET', '/positions/'.$this->position->getId());
        self::assertSelectorTextContains('body', 'Recruiter announcement');
        $this->post($this->position, '  Candidate reply  ');
        self::assertResponseRedirects('/positions/'.$this->position->getId());
        self::assertSame('Candidate reply', $this->em->getConnection()->fetchOne('SELECT content FROM discussion_post WHERE author_user_id = ?', [$this->candidate->getId()]));
        foreach (['/positions/'.$hidden->getId(), '/positions/'.$hidden->getId().'/discussion/posts?after=0'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('POST', '/positions/'.$hidden->getId().'/discussion', ['content' => 'Nope', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/positions/'.$hidden->getId());
        self::assertResponseIsSuccessful();
    }

    public function testDiscussionIsAppendOnlyChronologicalAndPollsOnlyNewPosts(): void
    {
        $this->client->loginUser($this->recruiter);
        $this->post($this->position, 'First post');
        $first = (int) $this->em->getConnection()->fetchOne('SELECT id FROM discussion_post WHERE position_id = ? ORDER BY id LIMIT 1', [$this->position->getId()]);
        $this->post($this->position, 'Second post');
        $this->client->request('GET', '/positions/'.$this->position->getId());
        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        self::assertLessThan(strpos($body, 'Second post'), strpos($body, 'First post'));
        $this->client->request('GET', '/positions/'.$this->position->getId().'/discussion/posts?after='.$first);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Second post', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('First post', $this->client->getResponse()->getContent());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM discussion_post WHERE position_id = ?', [$this->position->getId()]));

        $position = $this->em->find(Position::class, $this->position->getId());
        $author = $this->em->find(User::class, $this->recruiter->getId());
        for ($number = 1; $number <= 50; ++$number) {
            $this->em->persist(new DiscussionPost($position, $author, 'Backlog '.$number));
        }
        $this->em->flush();
        $this->client->request('GET', '/positions/'.$this->position->getId());
        self::assertCount(50, $this->client->getCrawler()->filter('[data-post-id]'));
        self::assertSelectorTextNotContains('body', 'First post');
        self::assertSelectorTextContains('body', 'Backlog 50');
        $this->client->request('GET', '/positions/'.$this->position->getId().'/discussion/posts?after='.$first);
        self::assertCount(50, $this->client->getCrawler()->filter('[data-post-id]'));
        self::assertStringNotContainsString('Backlog 50', $this->client->getResponse()->getContent());
        $lastId = $this->client->getCrawler()->filter('[data-post-id]')->last()->attr('data-post-id');
        $this->client->request('GET', '/positions/'.$this->position->getId().'/discussion/posts?after='.$lastId);
        self::assertSelectorTextContains('body', 'Backlog 50');
    }

    public function testDiscussionMarkdownStripsHtmlAndUnsafeLinks(): void
    {
        $this->client->loginUser($this->recruiter);
        $this->post($this->position, 'Hello **bold** <script>alert(1)</script> [bad](javascript:alert(1)) [good](https://example.com)');
        $this->client->request('GET', '/positions/'.$this->position->getId());
        $body = $this->client->getCrawler()->filter('.discussion-content')->html();
        self::assertStringContainsString('<strong>bold</strong>', $body);
        self::assertStringContainsString('href="https://example.com"', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('href="javascript:', $body);
    }

    public function testRecruiterCandidateAuthorLinkShowsOnlyPublicProjection(): void
    {
        $project = new Project($this->profile, 'Analytical Engine', new \DateTimeImmutable('2025-01-01'), null, 'Professional project');
        $this->em->persist($project);
        $this->em->flush();
        $this->client->loginUser($this->candidate);
        $this->post($this->position, 'Candidate note');
        $this->client->request('GET', '/positions/'.$this->position->getId());
        self::assertSelectorNotExists('a[href="/candidates/'.$this->candidate->getId().'/public"]');
        $this->client->loginUser($this->recruiter);
        $this->client->request('GET', '/positions/'.$this->position->getId());
        self::assertSelectorTextContains('a[href="/candidates/'.$this->candidate->getId().'/public"]', 'Ada Lovelace');
        $this->client->request('GET', '/candidates/'.$this->candidate->getId().'/public');
        self::assertResponseIsSuccessful();
        foreach (['Ada', 'Lovelace', 'London', 'Analytical Engine'] as $visible) {
            self::assertSelectorTextContains('body', $visible);
        }
        self::assertSelectorTextNotContains('body', $this->candidate->getEmail());
        $this->client->loginUser($this->candidate);
        $this->client->request('GET', '/candidates/'.$this->candidate->getId().'/public');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRecruiterLikesAndUnlikesIdempotentlyButCandidateCannot(): void
    {
        $cv = $this->cv($this->position, true);
        $this->client->loginUser($this->recruiter);
        $this->client->request('POST', '/cvs/'.$cv->getId().'/like', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $token = $this->likeToken($cv);
        $this->like($cv, $token);
        self::assertResponseRedirects('/cvs/'.$cv->getId());
        $this->like($cv, $token);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv_like WHERE cv_id = ?', [$cv->getId()]));
        $this->client->request('GET', '/cvs/'.$cv->getId());
        self::assertSelectorTextContains('body', '1 like');
        $unlikeToken = $this->client->getCrawler()->filter('form[action="/cvs/'.$cv->getId().'/unlike"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/cvs/'.$cv->getId().'/unlike', ['_token' => $unlikeToken]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv_like WHERE cv_id = ?', [$cv->getId()]));
        $this->client->request('POST', '/cvs/'.$cv->getId().'/unlike', ['_token' => $unlikeToken]);
        self::assertResponseRedirects('/cvs/'.$cv->getId());
        $this->client->loginUser($this->candidate);
        $this->client->request('POST', '/cvs/'.$cv->getId().'/like', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/cvs/'.$cv->getId());
        self::assertSelectorTextContains('body', '0 likes');
        self::assertSelectorNotExists('form[action="/cvs/'.$cv->getId().'/like"]');
    }

    public function testDraftAndHiddenCVsCannotBeLikedByRecruiter(): void
    {
        $draft = $this->cv($this->position, false);
        $hidden = $this->cv($this->ineligiblePosition(), true);
        $this->client->loginUser($this->recruiter);
        foreach ([$draft, $hidden] as $cv) {
            $this->client->request('POST', '/cvs/'.$cv->getId().'/like', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv_like'));
        $admin = new User('task012-admin-'.bin2hex(random_bytes(4)).'@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/positions/'.$hidden->getPosition()->getId());
        self::assertResponseIsSuccessful();
        $this->like($hidden, $this->likeToken($hidden));
        self::assertResponseRedirects('/cvs/'.$hidden->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv_like WHERE cv_id = ?', [$hidden->getId()]));
    }

    public function testLikeCountsAppearInBothListsAndDatabaseEnforcesUniqueness(): void
    {
        $cv = $this->cv($this->position, true);
        $this->client->loginUser($this->recruiter);
        $token = $this->likeToken($cv);
        $this->like($cv, $token);
        $other = new User('task012-other-'.bin2hex(random_bytes(4)).'@example.test');
        $other->setRoles(['ROLE_RECRUITER']);
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($other);
        $this->like($cv, $this->likeToken($cv));
        $this->client->request('GET', '/positions/'.$this->position->getId().'/cvs');
        self::assertSelectorTextContains('table', 'Likes');
        self::assertSelectorTextContains('tbody tr td:nth-child(3)', '2');
        $this->client->loginUser($this->candidate);
        $this->client->request('GET', '/cvs');
        self::assertSelectorTextContains('table', 'Likes');
        self::assertSelectorTextContains('tbody tr td:nth-child(3)', '2');

        $connection = $this->em->getConnection();
        $connection->createSavepoint('task012_unique');
        try {
            $connection->insert('cv_like', ['cv_id' => $cv->getId(), 'recruiter_user_id' => $this->recruiter->getId(), 'created_at' => '2026-09-23 00:00:00']);
            self::fail('The database accepted a duplicate like.');
        } catch (UniqueConstraintViolationException) {
            self::assertTrue(true);
        } finally {
            $connection->rollbackSavepoint('task012_unique');
        }
    }

    private function post(Position $position, string $content): void
    {
        $this->client->request('GET', '/positions/'.$position->getId());
        $token = $this->client->getCrawler()->filter('form[action="/positions/'.$position->getId().'/discussion"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/positions/'.$position->getId().'/discussion', ['_token' => $token, 'content' => $content]);
    }

    private function ineligiblePosition(): Position
    {
        $firstName = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $position = new Position('Restricted role', '', PositionAccessType::RESTRICTED, 0);
        (new PositionAccessRule($position, $firstName, AccessRuleOperator::EQUAL))->setTextExpected('Different');
        $this->em->persist($position);
        $this->em->flush();
        return $position;
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

    private function likeToken(CV $cv): string
    {
        $this->client->request('GET', '/cvs/'.$cv->getId());
        return $this->client->getCrawler()->filter('form[action="/cvs/'.$cv->getId().'/like"] input[name="_token"]')->attr('value');
    }

    private function like(CV $cv, string $token): void
    {
        $this->client->request('POST', '/cvs/'.$cv->getId().'/like', ['_token' => $token]);
    }
}
