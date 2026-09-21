<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SocialAccountResolver;
use App\Security\SocialAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class SocialAuthenticatorTest extends TestCase
{
    public function testOnlyNamedCallbackRoutesAreSupported(): void
    {
        $authenticator = new SocialAuthenticator(
            $this->createStub(ClientRegistry::class),
            new SocialAccountResolver($this->createStub(EntityManagerInterface::class)),
            $this->createStub(RouterInterface::class),
        );

        self::assertTrue($authenticator->supports($this->request('app_google_callback')));
        self::assertTrue($authenticator->supports($this->request('app_github_callback')));
        self::assertFalse($authenticator->supports($this->request('app_home', 'google')));
        self::assertFalse($authenticator->supports($this->request('app_login', 'github')));
    }

    public function testGoogleCallbackUsesGoogleClientDespiteUserControlledProviderParameter(): void
    {
        $token = new AccessToken(['access_token' => 'test-only-token']);
        $client = $this->createStub(OAuth2ClientInterface::class);
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new GoogleUser([
            'sub' => 'google-user-id', 'email' => 'person@example.test', 'email_verified' => true,
        ]));

        $registry = $this->createMock(ClientRegistry::class);
        $registry->expects(self::once())->method('getClient')->with('google')->willReturn($client);
        $authenticator = new SocialAuthenticator(
            $registry,
            new SocialAccountResolver($this->createStub(EntityManagerInterface::class)),
            $this->createStub(RouterInterface::class),
        );

        $passport = $authenticator->authenticate($this->request('app_google_callback', 'github'));

        self::assertInstanceOf(SelfValidatingPassport::class, $passport);
    }

    public function testGithubCallbackUsesGithubClientDespiteUserControlledProviderParameter(): void
    {
        $token = new AccessToken(['access_token' => 'test-only-token']);
        $client = $this->createStub(OAuth2ClientInterface::class);
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new GithubResourceOwner(['id' => 1234]));

        $registry = $this->createMock(ClientRegistry::class);
        $registry->expects(self::once())->method('getClient')->with('github')->willReturn($client);
        $authenticator = new SocialAuthenticator(
            $registry,
            new SocialAccountResolver($this->createStub(EntityManagerInterface::class)),
            $this->createStub(RouterInterface::class),
        );

        $passport = $authenticator->authenticate($this->request('app_github_callback', 'google'));

        self::assertInstanceOf(SelfValidatingPassport::class, $passport);
    }

    private function request(string $route, ?string $provider = null): Request
    {
        $request = Request::create('/connect/google/check', 'GET', $provider === null ? [] : ['provider' => $provider]);
        $request->attributes->set('_route', $route);

        return $request;
    }
}
