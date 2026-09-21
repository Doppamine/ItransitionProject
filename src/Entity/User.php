<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_normalized_email', columns: ['normalized_email'])]
final class User
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
