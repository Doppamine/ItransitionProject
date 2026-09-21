<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\ProfileAttributeValue;
use App\Entity\User;
use App\Enum\AttributeType;
use PHPUnit\Framework\TestCase;

final class ProfileAttributeValueTest extends TestCase
{
    public function testStringAndTextBothUseTextStorageAndTouchProfile(): void
    {
        foreach ([AttributeType::STRING, AttributeType::TEXT] as $type) {
            [$profile, $value] = $this->valueOfType($type);
            $before = $profile->getUpdatedAt();

            $value->setText('A value');

            self::assertSame('A value', $value->getTextValue());
            self::assertFalse($value->isEmpty());
            self::assertGreaterThan($before, $profile->getUpdatedAt());
        }
    }

    public function testNumericUsesDecimalStringWithoutFloatConversion(): void
    {
        [, $value] = $this->valueOfType(AttributeType::NUMERIC);

        $value->setNumeric('12345678901234.123456');

        self::assertSame('12345678901234.123456', $value->getNumericValue());
    }

    public function testInvalidOrOutOfRangeDecimalIsRejectedWithoutChangingValue(): void
    {
        [, $value] = $this->valueOfType(AttributeType::NUMERIC);
        $value->setNumeric('4.5');

        foreach (['1e3', '123456789012345.1', '1.1234567', 'nope'] as $invalid) {
            try {
                $value->setNumeric($invalid);
                self::fail('Expected invalid decimal to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame('4.5', $value->getNumericValue());
            }
        }
    }

    public function testDateUsesImmutableDate(): void
    {
        [, $value] = $this->valueOfType(AttributeType::DATE);
        $date = new \DateTimeImmutable('2026-09-21');

        $value->setDate($date);

        self::assertSame($date, $value->getDateValue());
    }

    public function testPeriodRequiresOrderedCompletePair(): void
    {
        [, $value] = $this->valueOfType(AttributeType::PERIOD);
        $start = new \DateTimeImmutable('2020-01-01');
        $end = new \DateTimeImmutable('2022-12-31');

        $value->setPeriod($start, $end);

        self::assertSame($start, $value->getPeriodStart());
        self::assertSame($end, $value->getPeriodEnd());

        $this->expectException(\InvalidArgumentException::class);
        $value->setPeriod($end, $start);
    }

    public function testPeriodComparesStoredCalendarDatesRatherThanTimes(): void
    {
        [, $value] = $this->valueOfType(AttributeType::PERIOD);
        $start = new \DateTimeImmutable('2026-09-21 18:00:00');
        $end = new \DateTimeImmutable('2026-09-21 08:00:00');

        $value->setPeriod($start, $end);

        self::assertSame($start, $value->getPeriodStart());
        self::assertSame($end, $value->getPeriodEnd());
    }

    public function testBooleanFalseIsPresentRatherThanMissing(): void
    {
        [, $value] = $this->valueOfType(AttributeType::BOOLEAN);

        $value->setBoolean(false);

        self::assertFalse($value->getBooleanValue());
        self::assertFalse($value->isEmpty());
    }

    public function testSelectAcceptsAnOptionFromItsDefinition(): void
    {
        [$profile, $value] = $this->valueOfType(AttributeType::SELECT);
        $option = $value->getDefinition()->addOption('English', 0);
        $before = $profile->getUpdatedAt();

        $value->setOption($option);

        self::assertSame($option, $value->getOption());
        self::assertGreaterThan($before, $profile->getUpdatedAt());
    }

    public function testSelectRejectsAnOptionFromAnotherDefinition(): void
    {
        [, $value] = $this->valueOfType(AttributeType::SELECT);
        $other = new AttributeDefinition(new AttributeCategory('Other'), 'Other selection', AttributeType::SELECT);
        $foreignOption = $other->addOption('Foreign', 0);

        $this->expectException(\InvalidArgumentException::class);
        $value->setOption($foreignOption);
    }

    public function testImageStoresOnlyExternalKey(): void
    {
        [, $value] = $this->valueOfType(AttributeType::IMAGE);

        $value->setImageKey('candidate/portrait-123');

        self::assertSame('candidate/portrait-123', $value->getImageKey());
    }

    public function testWrongTypedOperationsAreRejected(): void
    {
        [$profile, $value] = $this->valueOfType(AttributeType::STRING);
        $before = $profile->getUpdatedAt();

        foreach ([
            static fn () => $value->setNumeric('1'),
            static fn () => $value->setDate(new \DateTimeImmutable('2020-01-01')),
            static fn () => $value->setPeriod(new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-01-02')),
            static fn () => $value->setBoolean(true),
            static fn () => $value->setImageKey('key'),
        ] as $wrongOperation) {
            try {
                $wrongOperation();
                self::fail('Expected wrong representation to be rejected.');
            } catch (\LogicException) {
                self::assertTrue($value->isEmpty());
                self::assertSame($before, $profile->getUpdatedAt());
            }
        }

        $selectDefinition = new AttributeDefinition(new AttributeCategory('Other'), 'Options', AttributeType::SELECT);
        $option = $selectDefinition->addOption('One', 0);
        $this->expectException(\LogicException::class);
        $value->setOption($option);
    }

    public function testClearingValueReturnsItToEmptyAndTouchesRoot(): void
    {
        [$profile, $value] = $this->valueOfType(AttributeType::BOOLEAN);
        $value->setBoolean(false);
        $before = $profile->getUpdatedAt();

        $value->clearValue();

        self::assertTrue($value->isEmpty());
        self::assertNull($value->getBooleanValue());
        self::assertGreaterThan($before, $profile->getUpdatedAt());
    }

    /** @return array{Profile, ProfileAttributeValue} */
    private function valueOfType(AttributeType $type): array
    {
        $definition = new AttributeDefinition(new AttributeCategory('Profile'), 'Field', $type);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.com'), []);

        return [$profile, $profile->selectAttribute($definition)];
    }
}
