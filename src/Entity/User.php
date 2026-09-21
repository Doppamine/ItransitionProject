<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_normalized_email', columns: ['normalized_email'])]
final class User implements UserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $normalizedEmail;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $roles = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $blocked = false;

    public function __construct(string $email)
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->changeEmail($email);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNormalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_unique($roles));
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $this->getUserIdentifier() !== $user->getUserIdentifier() || $this->blocked !== $user->blocked) {
            return false;
        }

        $currentRoles = $this->roles;
        $refreshedRoles = $user->roles;
        sort($currentRoles);
        sort($refreshedRoles);

        return $currentRoles === $refreshedRoles;
    }

    public function eraseCredentials(): void
    {
    }

    public function changeEmail(string $email): void
    {
        $email = trim($email);

        if ($email === '') {
            throw new \InvalidArgumentException('Email cannot be empty.');
        }

        $this->email = $email;
        $this->normalizedEmail = mb_strtolower($email, 'UTF-8');
    }
}
