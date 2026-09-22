<?php

declare(strict_types=1);

namespace App\Enum;

enum PositionAccessType: string
{
    case PUBLIC = 'public';
    case RESTRICTED = 'restricted';
}
