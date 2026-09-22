<?php

declare(strict_types=1);

namespace App\Position;

use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;

final class AccessRuleOperators
{
    /** @return list<AccessRuleOperator> */
    public static function for(AttributeType $type): array
    {
        return match ($type) {
            AttributeType::STRING, AttributeType::TEXT, AttributeType::BOOLEAN, AttributeType::SELECT, AttributeType::PERIOD => [
                AccessRuleOperator::EQUAL,
                AccessRuleOperator::NOT_EQUAL,
            ],
            AttributeType::NUMERIC, AttributeType::DATE => [
                AccessRuleOperator::EQUAL,
                AccessRuleOperator::NOT_EQUAL,
                AccessRuleOperator::GREATER_THAN,
                AccessRuleOperator::GREATER_THAN_OR_EQUAL,
                AccessRuleOperator::LESS_THAN,
                AccessRuleOperator::LESS_THAN_OR_EQUAL,
            ],
            AttributeType::IMAGE => [],
        };
    }

    public static function supports(AttributeType $type, AccessRuleOperator $operator): bool
    {
        return in_array($operator, self::for($type), true);
    }
}
