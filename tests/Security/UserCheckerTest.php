<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

final class UserCheckerTest extends TestCase
{
    public function testBlockedUserIsRejectedDuringAuthentication(): void
    {
        $user = new User('blocked@example.test');
        $user->setBlocked(true);

        $this->expectException(AccountStatusException::class);

        (new UserChecker())->checkPreAuth($user);
    }

    public function testUnblockedUserPassesAuthenticationCheck(): void
    {
        $user = new User('active@example.test');
        (new UserChecker())->checkPreAuth($user);

        self::assertFalse($user->isBlocked());
    }
}
