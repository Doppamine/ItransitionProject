<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\GoogleUser;

final class VerifiedProviderEmail
{
    public static function google(GoogleUser $user): ?string
    {
        return $user->getEmailVerified() === true ? self::nonBlank($user->getEmail()) : null;
    }

    /** @param list<array<string, mixed>> $emails */
    public static function github(array $emails): ?string
    {
        foreach ($emails as $email) {
            if (($email['primary'] ?? null) === true && ($email['verified'] ?? null) === true) {
                return self::nonBlank($email['email'] ?? null);
            }
        }

        return null;
    }

    private static function nonBlank(mixed $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }

        $email = trim($email);

        return $email === '' ? null : $email;
    }
}
