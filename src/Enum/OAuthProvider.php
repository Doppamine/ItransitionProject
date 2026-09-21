<?php

declare(strict_types=1);

namespace App\Enum;

enum OAuthProvider: string
{
    case GOOGLE = 'google';
    case GITHUB = 'github';
}
