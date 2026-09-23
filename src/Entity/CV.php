<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CVStatus;
use App\Repository\CVRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CVRepository::class)]
#[ORM\Table(name: 'cv')]
#[ORM\UniqueConstraint(name: 'uniq_cv_profile_position', columns: ['profile_id', 'position_id'])]
#[ORM\Index(name: 'idx_cv_position_status', columns: ['position_id', 'status'])]
final class CV
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Profile $profile;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Position $position;

    #[ORM\Column(length: 12, enumType: CVStatus::class)]
    private CVStatus $status = CVStatus::DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(Profile $profile, Position $position)
    {
        $this->profile = $profile;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getProfile(): Profile { return $this->profile; }
    public function getPosition(): Position { return $this->position; }
    public function getStatus(): CVStatus { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    public function publish(): void
    {
        if ($this->status === CVStatus::PUBLISHED) {
            return;
        }

        $this->status = CVStatus::PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable();
        $next = $this->updatedAt->modify('+1 second');
        $this->updatedAt = $this->publishedAt > $next ? $this->publishedAt : $next;
    }
}
