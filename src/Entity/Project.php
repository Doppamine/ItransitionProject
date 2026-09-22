<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'candidate_project')]
#[ORM\Index(name: 'idx_candidate_project_recent', columns: ['profile_id', 'end_date', 'start_date', 'id'])]
final class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Profile $profile;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'candidate_project_tag')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    public function __construct(Profile $profile, string $name, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate, string $description)
    {
        $this->profile = $profile;
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->update($name, $startDate, $endDate, $description);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfile(): Profile
    {
        return $this->profile;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<Tag> */
    public function getTags(): array
    {
        return array_values($this->tags->toArray());
    }

    public function update(string $name, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate, string $description): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Enter a project name of at most 255 characters.');
        }
        if ($endDate !== null && $startDate > $endDate) {
            throw new \InvalidArgumentException('End date must be on or after start date.');
        }

        $this->name = $name;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->description = $description;
        $this->touch();
    }

    /** @param list<Tag> $tags */
    public function replaceTags(array $tags): void
    {
        foreach ($this->tags->toArray() as $current) {
            if (!in_array($current, $tags, true)) {
                $this->tags->removeElement($current);
            }
        }
        foreach ($tags as $tag) {
            if (!$this->tags->contains($tag)) {
                $this->tags->add($tag);
            }
        }
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
