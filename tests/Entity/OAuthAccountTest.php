<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\OAuthAccount;
use App\Entity\User;
use App\Enum\OAuthProvider;
use PHPUnit\Framework\TestCase;

final class OAuthAccountTest extends TestCase
{
    public function testAccountKeepsProviderIdentityAndOwnerWithoutTokens(): void
    {
        $user = new User('person@example.com');
        $account = new OAuthAccount($user, OAuthProvider::GOOGLE, 'google-123');

        self::assertSame($user, $account->getUser());
        self::assertSame(OAuthProvider::GOOGLE, $account->getProvider());
        self::assertSame('google-123', $account->getProviderUserId());
        self::assertInstanceOf(\DateTimeImmutable::class, $account->getCreatedAt());
    }

    public function testBlankProviderIdentityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OAuthAccount(new User('person@example.com'), OAuthProvider::GITHUB, ' ');
    }
}
