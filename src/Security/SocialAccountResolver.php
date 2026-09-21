<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AttributeDefinition;
use App\Entity\OAuthAccount;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\OAuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final readonly class SocialAccountResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param callable(): ?string $verifiedEmail */
    public function resolve(OAuthProvider $provider, string $providerUserId, callable $verifiedEmail): User
    {
        $account = $this->entityManager->getRepository(OAuthAccount::class)->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);

        if ($account !== null) {
            return $account->getUser();
        }

        $email = trim($verifiedEmail() ?? '');

        if ($email === '') {
            throw new CustomUserMessageAuthenticationException('A verified email address is required to sign in.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($provider, $providerUserId, $email): User {
            $account = $this->entityManager->getRepository(OAuthAccount::class)->findOneBy([
                'provider' => $provider,
                'providerUserId' => $providerUserId,
            ]);

            if ($account !== null) {
                return $account->getUser();
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy([
                'normalizedEmail' => mb_strtolower($email, 'UTF-8'),
            ]);

            if ($user === null) {
                $user = new User($email);
                $user->setRoles(['ROLE_CANDIDATE']);
                $this->entityManager->persist($user);

                $builtIns = $this->entityManager->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
                $this->entityManager->persist(Profile::createWithBuiltIns($user, $builtIns));
            }

            $this->entityManager->persist(new OAuthAccount($user, $provider, $providerUserId));

            return $user;
        });
    }
}
