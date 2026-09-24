<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\CVLike;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinalRepairSecurityTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private User $candidate;
    private User $recruiter;
    private Profile $profile;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(4));
        $this->admin = new User('repair-admin-'.$suffix.'@example.test');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->candidate = new User('repair-candidate-'.$suffix.'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->recruiter = new User('repair-recruiter-'.$suffix.'@example.test');
        $this->recruiter->setRoles(['ROLE_RECRUITER']);
        $definitions = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $this->profile = Profile::createWithBuiltIns($this->candidate, $definitions);
        foreach ($this->profile->getValues() as $value) {
            if ($value->getDefinition()->getNormalizedName() === 'first name') {
                $value->setText('Repair Candidate');
            }
        }
        foreach ([$this->admin, $this->candidate, $this->recruiter, $this->profile] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }
        parent::tearDown();
    }

    public function testAdminOpensCandidateOwnerPagesAndExitsImpersonation(): void
    {
        $project = new Project($this->profile, 'Owner project', new \DateTimeImmutable('2024-01-01'), null, 'Project description');
        $position = new Position('Owner CV', '', PositionAccessType::PUBLIC, 0);
        $cv = new CV($this->profile, $position);
        foreach ([$project, $position, $cv] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $client = static::getClient();
        $client->loginUser($this->admin);
        $page = $client->request('GET', '/admin/users');
        $token = $page->filter('form[action="/admin/users/action"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'open_as_user', 'ids' => [(string) $this->candidate->getId()]]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseRedirects('/profile');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="Repair Candidate"]');
        self::assertSelectorExists('a[href*="_switch_user=_exit"]');
        $client->request('GET', '/profile/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Owner project');
        $client->request('GET', '/profile/projects/new');
        self::assertResponseIsSuccessful();
        $form = $client->getCrawler()->selectButton('Save project')->form();
        $form['project[name]'] = 'Created while impersonating';
        $form['project[startDate]'] = '2024-02-01';
        $form['project[description]'] = '**Owner edit**';
        $client->submit($form);
        self::assertResponseRedirects('/profile/projects');
        $created = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Created while impersonating']);
        self::assertNotNull($created);
        self::assertSame($this->profile->getId(), $created->getProfile()->getId());
        $client->request('GET', '/profile/projects/'.$project->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/cvs');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/cvs/'.$cv->getId().'"]');
        $client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseIsSuccessful();

        $page = $client->request('GET', '/profile');
        $exit = $page->filter('a[href*="_switch_user=_exit"]')->attr('href');
        $client->request('GET', $exit);
        self::assertResponseRedirects('/admin/users');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', $this->admin->getEmail());
        self::assertSelectorNotExists('a[href*="_switch_user=_exit"]');
        $client->request('GET', '/profile');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyAdminMayInitiateImpersonationAndTargetMustBeCandidate(): void
    {
        $client = static::getClient();
        foreach ([$this->candidate, $this->recruiter] as $actor) {
            $client->loginUser($actor);
            $client->request('GET', '/profile?_switch_user='.rawurlencode($this->candidate->getEmail()));
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/users/action', ['action' => 'open_as_user', 'ids' => [(string) $this->candidate->getId()]]);
            self::assertResponseStatusCodeSame(403);
        }

        $client->loginUser($this->admin);
        $page = $client->request('GET', '/admin/users');
        $token = $page->filter('form[action="/admin/users/action"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'open_as_user', 'ids' => [(string) $this->recruiter->getId()]]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'open_as_user', 'ids' => [(string) $this->candidate->getId(), (string) $this->recruiter->getId()]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCandidateDeletesOwnCVAndDatabaseCascadesLikes(): void
    {
        $position = new Position('Delete own CV', '', PositionAccessType::PUBLIC, 0);
        $cv = new CV($this->profile, $position);
        $like = new CVLike($cv, $this->recruiter);
        $hidden = new Position('Hidden CV', '', PositionAccessType::RESTRICTED, 0);
        $hiddenCV = new CV($this->profile, $hidden);
        foreach ([$position, $cv, $like, $hidden, $hiddenCV] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $id = $cv->getId();

        $client = static::getClient();
        $client->loginUser($this->candidate);
        $page = $client->request('GET', '/cvs/'.$id);
        self::assertSelectorExists('form[action="/cvs/'.$id.'/delete"]');
        $token = $page->filter('form[action="/cvs/'.$id.'/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', '/cvs/'.$id.'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/cvs/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/cvs');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv_like WHERE cv_id = ?', [$id]));
        $client->request('GET', '/cvs/'.$hiddenCV->getId());
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/cvs/'.$hiddenCV->getId().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$hiddenCV->getId()]));
    }

    public function testRecruiterCannotDeleteCVButAdminCan(): void
    {
        $position = new Position('Admin deletes CV', '', PositionAccessType::PUBLIC, 0);
        $cv = new CV($this->profile, $position);
        $this->em->persist($position);
        $this->em->persist($cv);
        $this->em->flush();
        $id = $cv->getId();
        $client = static::getClient();
        $client->request('POST', '/cvs/'.$id.'/delete', ['_token' => 'invalid']);
        self::assertResponseRedirects('/login');
        $client->loginUser($this->admin);
        $page = $client->request('GET', '/cvs/'.$id);
        $token = $page->filter('form[action="/cvs/'.$id.'/delete"] input[name="_token"]')->attr('value');
        $client->loginUser($this->recruiter);
        $client->request('POST', '/cvs/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$id]));
        $client->loginUser($this->admin);
        $client->request('POST', '/cvs/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/positions/'.$position->getId().'/cvs');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$id]));
    }
}
