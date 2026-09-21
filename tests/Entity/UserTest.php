<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserTest extends TestCase
{
    public function testEmailIsTrimmedAndUnicodeLowercasedForUniqueness(): void
    {
        $user = new User('  RÉSUMÉ@Example.COM  ');

        self::assertSame('RÉSUMÉ@Example.COM', $user->getEmail());
        self::assertSame('résumé@example.com', $user->getNormalizedEmail());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
    }

    public function testBlankEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(" \t ");
    }

    public function testChangingEmailKeepsNormalizationInSync(): void
    {
        $user = new User('first@example.com');

        $user->changeEmail('  SECOND@Example.COM  ');

        self::assertSame('SECOND@Example.COM', $user->getEmail());
        self::assertSame('second@example.com', $user->getNormalizedEmail());
    }

    public function testSecurityIdentifierAndUniqueRoles(): void
    {
        $user = new User('  Person@Example.com ');
        $user->setRoles(['ROLE_ADMIN', 'ROLE_RECRUITER', 'ROLE_ADMIN']);

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertSame('Person@Example.com', $user->getUserIdentifier());
        self::assertSame(['ROLE_ADMIN', 'ROLE_RECRUITER'], $user->getRoles());
        self::assertFalse($user->isBlocked());
    }

    public function testSessionEqualityIgnoresRoleOrderButDetectsSecurityChanges(): void
    {
        $sessionUser = new User('person@example.com');
        $sessionUser->setRoles(['ROLE_ADMIN', 'ROLE_RECRUITER']);
        $refreshed = new User('person@example.com');
        $refreshed->setRoles(['ROLE_RECRUITER', 'ROLE_ADMIN']);

        self::assertTrue($sessionUser->isEqualTo($refreshed));

        $refreshed->setBlocked(true);
        self::assertFalse($sessionUser->isEqualTo($refreshed));

        $refreshed->setBlocked(false);
        $refreshed->setRoles(['ROLE_RECRUITER']);
        self::assertFalse($sessionUser->isEqualTo($refreshed));

        $refreshed->setRoles(['ROLE_ADMIN', 'ROLE_RECRUITER']);
        $refreshed->changeEmail('other@example.com');
        self::assertFalse($sessionUser->isEqualTo($refreshed));
    }
}
