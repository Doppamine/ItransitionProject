<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Security\UserChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class Task014AdminTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private User $subject;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(4));
        $this->admin = new User('admin-'.$suffix.'@example.test');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->subject = new User('subject-'.$suffix.'@example.test');
        $this->em->persist($this->admin);
        $this->em->persist($this->subject);
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

    public function testAdminListIsRestrictedAndUsesOneSelectionToolbar(): void
    {
        $client = static::getClient();
        $client->request('GET', '/admin/users');
        self::assertResponseRedirects('/login');
        $client->loginUser($this->subject);
        $client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($this->admin);
        $home = $client->request('GET', '/');
        $localeToken = $home->filter('form[action="/preferences/locale"] input[name="_token"]')->attr('value');
        $client->request('POST', '/preferences/locale', ['_token' => $localeToken, 'locale' => 'ru']);
        $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Пользователи');
        self::assertSelectorTextContains('select[name="action"]', 'Заблокировать');
        self::assertSelectorExists('table th');
        self::assertSelectorCount(1, 'form[action="/admin/users/action"] select[name="action"]');
        self::assertSelectorExists('input[name="ids[]"][value="'.$this->subject->getId().'"]');
        self::assertSelectorNotExists('tr form button');
        self::assertSelectorExists('a[href="/admin/users"]');
    }

    public function testBlockUnblockAndWhitelistedCsrfProtectedActions(): void
    {
        $client = static::getClient();
        $client->loginUser($this->admin);
        $page = $client->request('GET', '/admin/users');
        $token = $page->filter('form[action="/admin/users/action"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/users/action', ['_token' => 'bad', 'action' => 'block', 'ids' => [(string) $this->subject->getId()]]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'arbitrary_role', 'ids' => [(string) $this->subject->getId()]]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'block', 'ids' => [(string) $this->subject->getId()]]);
        self::assertResponseRedirects('/admin/users');
        $subject = $this->em->find(User::class, $this->subject->getId());
        self::assertTrue($subject->isBlocked());
        try {
            (new UserChecker())->checkPreAuth($subject);
            self::fail('Blocked account passed UserChecker.');
        } catch (CustomUserMessageAccountStatusException) {
            self::assertTrue(true);
        }
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'unblock', 'ids' => [(string) $this->subject->getId()]]);
        self::assertResponseRedirects('/admin/users');
        $this->em->clear();
        $subject = $this->em->find(User::class, $this->subject->getId());
        self::assertFalse($subject->isBlocked());
        (new UserChecker())->checkPreAuth($subject);
    }

    public function testCandidateRoleInitializesProfileAndOwnAdminRemovalRevokesAccess(): void
    {
        $client = static::getClient();
        $client->loginUser($this->admin);
        $page = $client->request('GET', '/admin/users');
        $token = $page->filter('form[action="/admin/users/action"] input[name="_token"]')->attr('value');
        $id = (string) $this->subject->getId();
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'add_candidate', 'ids' => [$id]]);
        self::assertResponseRedirects('/admin/users');
        $subject = $this->em->find(User::class, $this->subject->getId());
        self::assertContains('ROLE_CANDIDATE', $subject->getRoles());
        $profileId = $this->em->getConnection()->fetchOne('SELECT id FROM profile WHERE user_id = ?', [$this->subject->getId()]);
        self::assertNotFalse($profileId);
        self::assertSame((int) $this->em->getRepository(AttributeDefinition::class)->count(['isBuiltIn' => true]), (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile_attribute_value WHERE profile_id = ?', [$profileId]));
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'add_candidate', 'ids' => [$id]]);
        self::assertSame((string) $profileId, (string) $this->em->getConnection()->fetchOne('SELECT id FROM profile WHERE user_id = ?', [$this->subject->getId()]));
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'remove_candidate', 'ids' => [$id]]);
        $this->em->clear();
        $subject = $this->em->find(User::class, $this->subject->getId());
        self::assertNotContains('ROLE_CANDIDATE', $subject->getRoles());
        self::assertSame((string) $profileId, (string) $this->em->getConnection()->fetchOne('SELECT id FROM profile WHERE user_id = ?', [$this->subject->getId()]));
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'remove_admin', 'ids' => [(string) $this->admin->getId()]]);
        self::assertResponseRedirects('/');
        $this->em->clear();
        $admin = $this->em->find(User::class, $this->admin->getId());
        self::assertNotContains('ROLE_ADMIN', $admin->getRoles());
        $client->request('GET', '/admin/users');
        self::assertResponseRedirects('/login');
    }

    public function testDeleteUsesDatabaseCascadesForDependentData(): void
    {
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $profile = Profile::createWithBuiltIns($this->subject, $builtIns);
        $position = new Position('Deletion test', '', PositionAccessType::PUBLIC, 0);
        $cv = new CV($profile, $position);
        $this->em->persist($profile);
        $this->em->persist($position);
        $this->em->persist($cv);
        $this->em->flush();
        $profileId = $profile->getId();
        $cvId = $cv->getId();
        $client = static::getClient();
        $client->loginUser($this->admin);
        $page = $client->request('GET', '/admin/users');
        $token = $page->filter('form[action="/admin/users/action"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/users/action', ['_token' => $token, 'action' => 'delete', 'ids' => [(string) $this->subject->getId()]]);
        self::assertResponseRedirects('/admin/users');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = ?', [$this->subject->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile WHERE id = ?', [$profileId]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cv WHERE id = ?', [$cvId]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM profile_attribute_value WHERE profile_id = ?', [$profileId]));
    }
}
