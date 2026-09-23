<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CVLikeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CVLikeRepository::class)]
#[ORM\Table(name: 'cv_like')]
#[ORM\UniqueConstraint(name: 'uniq_cv_like_cv_recruiter', columns: ['cv_id', 'recruiter_user_id'])]
#[ORM\Index(name: 'idx_cv_like_recruiter', columns: ['recruiter_user_id'])]
final class CVLike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CV::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CV $cv;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recruiter_user_id', nullable: false, onDelete: 'CASCADE')]
    private User $recruiter;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CV $cv, User $recruiter)
    {
        $this->cv = $cv;
        $this->recruiter = $recruiter;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCV(): CV { return $this->cv; }
    public function getRecruiter(): User { return $this->recruiter; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
