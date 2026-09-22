<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PositionAccessType;
use App\Repository\PositionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'position')]
#[ORM\Index(name: 'idx_position_listing', columns: ['updated_at', 'id'])]
#[ORM\Index(name: 'idx_position_access', columns: ['access_type', 'updated_at', 'id'])]
final class Position
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $shortDescription;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PositionAccessType::class)]
    private PositionAccessType $accessType;

    #[ORM\Column]
    private int $maxProjects;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $version = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PositionAttribute> */
    #[ORM\OneToMany(mappedBy: 'position', targetEntity: PositionAttribute::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $attributes;

    /** @var Collection<int, PositionProjectTag> */
    #[ORM\OneToMany(mappedBy: 'position', targetEntity: PositionProjectTag::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $projectTagLinks;

    /** @var Collection<int, PositionAccessRule> */
    #[ORM\OneToMany(mappedBy: 'position', targetEntity: PositionAccessRule::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $accessRules;

    public function __construct(string $title, string $shortDescription, PositionAccessType $accessType, int $maxProjects)
    {
        $this->attributes = new ArrayCollection();
        $this->projectTagLinks = new ArrayCollection();
        $this->accessRules = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->update($title, $shortDescription, $accessType, $maxProjects);
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getShortDescription(): string { return $this->shortDescription; }
    public function getAccessType(): PositionAccessType { return $this->accessType; }
    public function getMaxProjects(): int { return $this->maxProjects; }
    public function getVersion(): ?int { return $this->version; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return list<PositionAttribute> */
    public function getAttributes(): array
    {
        $attributes = array_values($this->attributes->toArray());
        usort($attributes, static fn (PositionAttribute $a, PositionAttribute $b): int => $a->getSortOrder() <=> $b->getSortOrder());
        return $attributes;
    }

    /** @return list<PositionProjectTag> */
    public function getProjectTagLinks(): array { return array_values($this->projectTagLinks->toArray()); }

    /** @return list<Tag> */
    public function getProjectTags(): array
    {
        return array_map(static fn (PositionProjectTag $link): Tag => $link->getTag(), $this->getProjectTagLinks());
    }

    /** @return list<PositionAccessRule> */
    public function getAccessRules(): array { return array_values($this->accessRules->toArray()); }

    public function update(string $title, string $shortDescription, PositionAccessType $accessType, int $maxProjects): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Enter a title of at most 255 characters.');
        }
        if (mb_strlen($shortDescription) > 2000) {
            throw new \InvalidArgumentException('Short description must be at most 2000 characters.');
        }
        if ($maxProjects < 0) {
            throw new \InvalidArgumentException('Maximum projects cannot be negative.');
        }
        $this->title = $title;
        $this->shortDescription = $shortDescription;
        $this->accessType = $accessType;
        $this->maxProjects = $maxProjects;
        $this->touch();
    }

    public function addAttribute(AttributeDefinition $definition, int $sortOrder): PositionAttribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->getDefinition() === $definition) {
                throw new \InvalidArgumentException('This attribute is already selected.');
            }
        }
        $attribute = new PositionAttribute($this, $definition, $sortOrder);
        $this->attributes->add($attribute);
        $this->touch();
        return $attribute;
    }

    public function removeAttribute(PositionAttribute $attribute): void
    {
        if ($attribute->getPosition() !== $this || !$this->attributes->removeElement($attribute)) {
            throw new \InvalidArgumentException('Attribute does not belong to this Position.');
        }
        $this->touch();
    }

    public function moveAttribute(PositionAttribute $attribute, int $sortOrder): void
    {
        if ($attribute->getPosition() !== $this || !$this->attributes->contains($attribute)) {
            throw new \InvalidArgumentException('Attribute does not belong to this Position.');
        }
        $attribute->changeSortOrder($sortOrder);
        $this->touch();
    }

    public function addProjectTag(Tag $tag): PositionProjectTag
    {
        foreach ($this->projectTagLinks as $link) {
            if ($link->getTag() === $tag) {
                throw new \InvalidArgumentException('This project tag is already selected.');
            }
        }
        $link = new PositionProjectTag($this, $tag);
        $this->projectTagLinks->add($link);
        $this->touch();
        return $link;
    }

    public function removeProjectTag(PositionProjectTag $link): void
    {
        if ($link->getPosition() !== $this || !$this->projectTagLinks->removeElement($link)) {
            throw new \InvalidArgumentException('Project tag does not belong to this Position.');
        }
        $this->touch();
    }

    public function registerAccessRule(PositionAccessRule $rule): void
    {
        if ($rule->getPosition() !== $this || $this->accessRules->contains($rule)) {
            throw new \InvalidArgumentException('Access Rule does not belong to this Position or is already registered.');
        }
        $this->accessRules->add($rule);
        $this->touch();
    }

    public function removeAccessRule(PositionAccessRule $rule): void
    {
        if ($rule->getPosition() !== $this || !$this->accessRules->removeElement($rule)) {
            throw new \InvalidArgumentException('Access Rule does not belong to this Position.');
        }
        $this->touch();
    }

    public function ruleChanged(PositionAccessRule $rule): void
    {
        if (!$this->accessRules->contains($rule)) {
            throw new \InvalidArgumentException('Access Rule does not belong to this Position.');
        }
        $this->touch();
    }

    private function touch(): void
    {
        $now = new \DateTimeImmutable();
        if (isset($this->updatedAt)) {
            $next = $this->updatedAt->modify('+1 second');
            $now = $now > $next ? $now : $next;
        }
        $this->updatedAt = $now;
    }
}
