<?php

declare(strict_types=1);

namespace App\Enum;

enum AttributeType: string
{
    case STRING = 'string';
    case TEXT = 'text';
    case IMAGE = 'image';
    case NUMERIC = 'numeric';
    case DATE = 'date';
    case PERIOD = 'period';
    case BOOLEAN = 'boolean';
    case SELECT = 'select';
}
