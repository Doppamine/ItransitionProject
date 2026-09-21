<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\VerifiedProviderEmail;
use League\OAuth2\Client\Provider\GoogleUser;
use PHPUnit\Framework\TestCase;

final class VerifiedProviderEmailTest extends TestCase
{
    public function testGoogleRequiresExplicitEmailVerification(): void
    {
        self::assertSame('person@example.test', VerifiedProviderEmail::google(new GoogleUser([
            'sub' => 'google-id', 'email' => 'person@example.test', 'email_verified' => true,
        ])));
        self::assertNull(VerifiedProviderEmail::google(new GoogleUser([
            'sub' => 'google-id', 'email' => 'person@example.test', 'email_verified' => false,
        ])));
        self::assertNull(VerifiedProviderEmail::google(new GoogleUser([
            'sub' => 'google-id', 'email' => 'person@example.test',
        ])));
    }

    public function testGithubSelectsOnlyPrimaryVerifiedEmail(): void
    {
        $emails = [
            ['email' => 'unverified@example.test', 'primary' => true, 'verified' => false, 'visibility' => 'public'],
            ['email' => 'secondary@example.test', 'primary' => false, 'verified' => true, 'visibility' => null],
            ['email' => ' primary@example.test ', 'primary' => true, 'verified' => true, 'visibility' => null],
        ];

        self::assertSame('primary@example.test', VerifiedProviderEmail::github($emails));
        self::assertNull(VerifiedProviderEmail::github(array_slice($emails, 0, 2)));
        self::assertNull(VerifiedProviderEmail::github([]));
    }

    public function testMalformedGithubEmailRowsCannotBeUsedForLinking(): void
    {
        self::assertNull(VerifiedProviderEmail::github([
            ['email' => '', 'primary' => true, 'verified' => true],
            ['email' => 'public@example.test', 'primary' => 'true', 'verified' => true],
            'unexpected-row',
        ]));
    }
}
