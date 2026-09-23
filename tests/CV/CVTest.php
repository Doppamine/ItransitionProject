<?php

declare(strict_types=1);

namespace App\Tests\CV;

use App\Entity\CV;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\CVStatus;
use App\Enum\PositionAccessType;
use PHPUnit\Framework\TestCase;

final class CVTest extends TestCase
{
    public function testCreationPersistsOnlyIdentityAndDraftState(): void
    {
        $profile = Profile::createWithBuiltIns(new User('candidate@example.test'), []);
        $position = new Position('Developer', '', PositionAccessType::PUBLIC, 0);

        $cv = new CV($profile, $position);

        self::assertSame($profile, $cv->getProfile());
        self::assertSame($position, $cv->getPosition());
        self::assertSame(CVStatus::DRAFT, $cv->getStatus());
        self::assertNull($cv->getPublishedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $cv->getCreatedAt());
    }

    public function testPublishSetsStatusAndTimestampWithoutCopyingContent(): void
    {
        $cv = new CV(
            Profile::createWithBuiltIns(new User('candidate@example.test'), []),
            new Position('Developer', '', PositionAccessType::PUBLIC, 0),
        );
        $before = $cv->getUpdatedAt();

        $cv->publish();

        self::assertSame(CVStatus::PUBLISHED, $cv->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $cv->getPublishedAt());
        self::assertGreaterThan($before, $cv->getUpdatedAt());
    }
}
