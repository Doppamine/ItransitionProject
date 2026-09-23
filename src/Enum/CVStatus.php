<?php

declare(strict_types=1);

namespace App\Enum;

enum CVStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
}
