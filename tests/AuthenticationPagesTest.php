<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationPagesTest extends WebTestCase
{
    public function testHomeKeepsExistingTextAndShowsAnonymousLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Itransition Project');
        self::assertSelectorTextContains('body', 'Symfony application is running.');
        self::assertSelectorExists('nav a[href="/login"]');
    }

    public function testLoginPresentsGoogleAndGithubActions(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/connect/google"]');
        self::assertSelectorExists('a[href="/connect/github"]');
        self::assertSelectorTextContains('body', 'Continue with Google');
        self::assertSelectorTextContains('body', 'Continue with GitHub');
    }

    public function testAnonymousDashboardRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testMissingOAuthStateIsRejectedBeforeTokenExchange(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connect/google/check?code=untrusted&state=invalid');

        self::assertResponseRedirects('/login?error=1');
    }

    public function testGoogleStartUsesActualCallbackRouteAndState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connect/google');

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $parameters);
        self::assertSame('http://localhost/connect/google/check', $parameters['redirect_uri']);
        self::assertNotEmpty($parameters['state']);
    }

    public function testGithubStartUsesActualCallbackRouteAndState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connect/github');

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $parameters);
        self::assertSame('http://localhost/connect/github/check', $parameters['redirect_uri']);
        self::assertNotEmpty($parameters['state']);
    }
}
