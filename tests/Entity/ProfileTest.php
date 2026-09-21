<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function testBuiltInsAreSelectedAsEmptyValues(): void
    {
        $category = new AttributeCategory('Personal Information');
        $firstName = new AttributeDefinition($category, 'First Name', AttributeType::STRING, isBuiltIn: true);
        $photo = new AttributeDefinition($category, 'Personal Photo', AttributeType::IMAGE, isBuiltIn: true);
        $user = new User('candidate@example.com');

        $profile = Profile::createWithBuiltIns($user, [$firstName, $photo]);

        self::assertSame($user, $profile->getUser());
        self::assertCount(2, $profile->getValues());
        self::assertSame($firstName, $profile->getValueFor($firstName)?->getDefinition());
        self::assertTrue($profile->getValueFor($photo)->isEmpty());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());
    }

    public function testNonBuiltInCannotBeSuppliedAsARequiredBuiltIn(): void
    {
        $optional = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::TEXT);

        $this->expectException(\InvalidArgumentException::class);

        Profile::createWithBuiltIns(new User('candidate@example.com'), [$optional]);
    }

    public function testOptionalSelectionIsIdempotentAndTouchesRootOnlyOnChange(): void
    {
        $optional = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::TEXT);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.com'), []);
        $before = $profile->getUpdatedAt();

        $value = $profile->selectAttribute($optional);

        self::assertSame($value, $profile->getValueFor($optional));
        self::assertTrue($value->isEmpty());
        self::assertGreaterThan($before, $profile->getUpdatedAt());

        $after = $profile->getUpdatedAt();
        self::assertSame($value, $profile->selectAttribute($optional));
        self::assertCount(1, $profile->getValues());
        self::assertSame($after, $profile->getUpdatedAt());
    }

    public function testBuiltInValueCannotBeRemoved(): void
    {
        $builtIn = new AttributeDefinition(new AttributeCategory('Personal Information'), 'First Name', AttributeType::STRING, isBuiltIn: true);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.com'), [$builtIn]);

        $this->expectException(\LogicException::class);

        $profile->removeAttribute($builtIn);
    }

    public function testOptionalValueCanBeRemovedAndRootIsTouched(): void
    {
        $optional = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::TEXT);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.com'), []);
        $profile->selectAttribute($optional);
        $before = $profile->getUpdatedAt();

        $profile->removeAttribute($optional);

        self::assertNull($profile->getValueFor($optional));
        self::assertSame([], $profile->getValues());
        self::assertGreaterThan($before, $profile->getUpdatedAt());
    }

    public function testChildMutationAdvancesTheSecondPersistedByDoctrine(): void
    {
        $profile = Profile::createWithBuiltIns(new User('candidate@example.com'), []);
        $previous = new \DateTimeImmutable('2030-01-01 00:00:00');
        (new \ReflectionProperty(Profile::class, 'updatedAt'))->setValue($profile, $previous);

        $profile->selectAttribute(new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::TEXT));

        self::assertGreaterThan($previous->format('Y-m-d H:i:s'), $profile->getUpdatedAt()->format('Y-m-d H:i:s'));
    }
}
