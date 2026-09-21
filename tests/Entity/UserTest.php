<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

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
}
