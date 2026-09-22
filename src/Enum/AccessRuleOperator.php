<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessRuleOperator: string
{
    case EQUAL = 'equal';
    case NOT_EQUAL = 'not_equal';
    case GREATER_THAN = 'greater_than';
    case GREATER_THAN_OR_EQUAL = 'greater_than_or_equal';
    case LESS_THAN = 'less_than';
    case LESS_THAN_OR_EQUAL = 'less_than_or_equal';

    public function label(): string
    {
        return match ($this) {
            self::EQUAL => 'Equals',
            self::NOT_EQUAL => 'Does not equal',
            self::GREATER_THAN => 'Greater than',
            self::GREATER_THAN_OR_EQUAL => 'Greater than or equal',
            self::LESS_THAN => 'Less than',
            self::LESS_THAN_OR_EQUAL => 'Less than or equal',
        };
    }
}
