<?php

declare(strict_types=1);

namespace App\Tests\Position;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use App\Position\AccessRuleOperators;
use App\Position\PositionEligibilityChecker;
use PHPUnit\Framework\TestCase;

final class PositionEligibilityCheckerTest extends TestCase
{
    public function testOperatorMatrixHasOneExactDefinition(): void
    {
        self::assertSame([AccessRuleOperator::EQUAL, AccessRuleOperator::NOT_EQUAL], AccessRuleOperators::for(AttributeType::STRING));
        self::assertSame([AccessRuleOperator::EQUAL, AccessRuleOperator::NOT_EQUAL], AccessRuleOperators::for(AttributeType::TEXT));
        self::assertSame([
            AccessRuleOperator::EQUAL,
            AccessRuleOperator::NOT_EQUAL,
            AccessRuleOperator::GREATER_THAN,
            AccessRuleOperator::GREATER_THAN_OR_EQUAL,
            AccessRuleOperator::LESS_THAN,
            AccessRuleOperator::LESS_THAN_OR_EQUAL,
        ], AccessRuleOperators::for(AttributeType::NUMERIC));
        self::assertSame(AccessRuleOperators::for(AttributeType::NUMERIC), AccessRuleOperators::for(AttributeType::DATE));
        self::assertSame([AccessRuleOperator::EQUAL, AccessRuleOperator::NOT_EQUAL], AccessRuleOperators::for(AttributeType::BOOLEAN));
        self::assertSame([AccessRuleOperator::EQUAL, AccessRuleOperator::NOT_EQUAL], AccessRuleOperators::for(AttributeType::SELECT));
        self::assertSame([AccessRuleOperator::EQUAL, AccessRuleOperator::NOT_EQUAL], AccessRuleOperators::for(AttributeType::PERIOD));
        self::assertSame([], AccessRuleOperators::for(AttributeType::IMAGE));
    }

    public function testPublicAndRestrictedEligibilityRequiresAllNonEmptyValues(): void
    {
        $category = new AttributeCategory('Eligibility');
        $score = new AttributeDefinition($category, 'Score', AttributeType::NUMERIC);
        $active = new AttributeDefinition($category, 'Active', AttributeType::BOOLEAN);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.test'), []);
        $profile->selectAttribute($score)->setNumeric('7.500000');
        $profile->selectAttribute($active);

        $public = new Position('Public', '', PositionAccessType::PUBLIC, 0);
        self::assertTrue((new PositionEligibilityChecker())->isEligible($public, $profile));

        $restricted = new Position('Restricted', '', PositionAccessType::RESTRICTED, 0);
        self::assertFalse((new PositionEligibilityChecker())->isEligible($restricted, $profile));
        (new PositionAccessRule($restricted, $score, AccessRuleOperator::GREATER_THAN))->setNumericExpected('7.0');
        (new PositionAccessRule($restricted, $active, AccessRuleOperator::NOT_EQUAL))->setBooleanExpected(false);
        self::assertFalse((new PositionEligibilityChecker())->isEligible($restricted, $profile), 'An empty value must fail even for NOT_EQUAL.');
        $profile->getValueFor($active)?->setBoolean(true);
        self::assertTrue((new PositionEligibilityChecker())->isEligible($restricted, $profile));
    }

    public function testRepresentativeTypedComparisonsAreExactAndDecimalSafe(): void
    {
        $category = new AttributeCategory('Typed');
        $profile = Profile::createWithBuiltIns(new User('typed@example.test'), []);
        $position = new Position('Typed', '', PositionAccessType::RESTRICTED, 0);

        $name = new AttributeDefinition($category, 'Name', AttributeType::STRING);
        $date = new AttributeDefinition($category, 'Date', AttributeType::DATE);
        $period = new AttributeDefinition($category, 'Period', AttributeType::PERIOD);
        $choice = new AttributeDefinition($category, 'Choice', AttributeType::SELECT);
        $selected = $choice->addOption('Selected', 10);

        $profile->selectAttribute($name)->setText('Exact');
        $profile->selectAttribute($date)->setDate(new \DateTimeImmutable('2026-09-23'));
        $profile->selectAttribute($period)->setPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $profile->selectAttribute($choice)->setOption($selected);

        (new PositionAccessRule($position, $name, AccessRuleOperator::EQUAL))->setTextExpected('Exact');
        (new PositionAccessRule($position, $date, AccessRuleOperator::GREATER_THAN))->setDateExpected(new \DateTimeImmutable('2026-01-01'));
        (new PositionAccessRule($position, $period, AccessRuleOperator::EQUAL))->setPeriodExpected(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        (new PositionAccessRule($position, $choice, AccessRuleOperator::EQUAL))->setOptionExpected($selected);

        self::assertTrue((new PositionEligibilityChecker())->isEligible($position, $profile));

        $decimal = new AttributeDefinition($category, 'Decimal', AttributeType::NUMERIC);
        $profile->selectAttribute($decimal)->setNumeric('99999999999999.000001');
        (new PositionAccessRule($position, $decimal, AccessRuleOperator::GREATER_THAN))->setNumericExpected('99999999999999.000000');
        self::assertTrue((new PositionEligibilityChecker())->isEligible($position, $profile));
    }
}
