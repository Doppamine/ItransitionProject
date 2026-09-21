<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class SessionRefreshTest extends WebTestCase
{
    private KernelBrowser $client;
    private MutableUserProvider $provider;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $user = new User('session-refresh@example.test');
        $user->setRoles(['ROLE_CANDIDATE', 'ROLE_RECRUITER']);
        $this->provider = new MutableUserProvider($user);
        static::getContainer()->set('security.user.provider.concrete.app_user_provider', $this->provider);
        $this->client->loginUser($user);
    }

    public function testBlockingInvalidatesAnExistingSessionOnItsNextRequest(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $this->refreshAs(['ROLE_CANDIDATE', 'ROLE_RECRUITER'], blocked: true);
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testRoleRemovalInvalidatesAnExistingSession(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $this->refreshAs(['ROLE_CANDIDATE']);
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testRoleOrderAloneKeepsAnExistingSessionValid(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $this->refreshAs(['ROLE_RECRUITER', 'ROLE_CANDIDATE']);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'session-refresh@example.test');
    }

    /** @param list<string> $roles */
    private function refreshAs(array $roles, bool $blocked = false): void
    {
        $user = new User('session-refresh@example.test');
        $user->setRoles($roles);
        $user->setBlocked($blocked);
        $this->provider->currentUser = $user;
    }
}

final class MutableUserProvider implements UserProviderInterface
{
    public function __construct(public User $currentUser)
    {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->currentUser;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, User::class, true);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->currentUser;
    }
}
