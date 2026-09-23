<?php

declare(strict_types=1);

namespace App\Tests\CV;

use App\CV\CVAttributes;
use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use PHPUnit\Framework\TestCase;

final class CVAttributesTest extends TestCase
{
    public function testBuiltInsAppearOnceAndCurrentPositionOrderIsUsed(): void
    {
        $first = $this->definition('First Name', AttributeType::STRING, true);
        $last = $this->definition('Last Name', AttributeType::STRING, true);
        $language = $this->definition('Language', AttributeType::STRING);
        $gpa = $this->definition('GPA', AttributeType::NUMERIC);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.test'), [$first, $last]);
        $position = new Position('Developer', '', PositionAccessType::PUBLIC, 0);
        $position->addAttribute($gpa, 20);
        $position->addAttribute($first, 10);
        $attributes = new CVAttributes();

        self::assertSame([$first, $last], array_column($attributes->builtIns($profile, $position, [$first, $last]), 'definition'));
        self::assertSame([$gpa], array_column($attributes->position($profile, $position, [$first, $last]), 'definition'));

        $position->addAttribute($language, 5);
        self::assertSame([$language, $gpa], array_column($attributes->position($profile, $position, [$first, $last]), 'definition'));
    }

    public function testMissingIncludesAbsentRowsAndEmptyValuesButNotBooleanFalse(): void
    {
        $boolean = $this->definition('Available', AttributeType::BOOLEAN);
        $language = $this->definition('Language', AttributeType::STRING);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.test'), []);
        $position = new Position('Developer', '', PositionAccessType::PUBLIC, 0);
        $position->addAttribute($boolean, 0);
        $position->addAttribute($language, 1);
        $attributes = new CVAttributes();

        self::assertSame([true, true], array_column($attributes->position($profile, $position, []), 'missing'));
        $profile->selectAttribute($boolean)->setBoolean(false);
        self::assertSame([false, true], array_column($attributes->position($profile, $position, []), 'missing'));
        self::assertFalse($attributes->isComplete($profile, $position, []));
        $profile->selectAttribute($language)->setText('English');
        self::assertTrue($attributes->isComplete($profile, $position, []));
    }

    private function definition(string $name, AttributeType $type, bool $builtIn = false): AttributeDefinition
    {
        return new AttributeDefinition(new AttributeCategory('CV'), $name, $type, isBuiltIn: $builtIn);
    }
}
