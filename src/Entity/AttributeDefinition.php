<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttributeType;
use App\Repository\AttributeDefinitionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttributeDefinitionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_attribute_definition_normalized_name', columns: ['normalized_name'])]
final class AttributeDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttributeCategory::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AttributeCategory $category;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $normalizedName;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AttributeType::class)]
    private AttributeType $type;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBuiltIn;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $version = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AttributeOption> */
    #[ORM\OneToMany(mappedBy: 'definition', targetEntity: AttributeOption::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $options;

    public function __construct(AttributeCategory $category, string $name, AttributeType $type, string $description = '', bool $isBuiltIn = false)
    {
        $this->category = $category;
        $this->type = $type;
        $this->description = $description;
        $this->isBuiltIn = $isBuiltIn;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->options = new ArrayCollection();
        $this->rename($name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): AttributeCategory
    {
        return $this->category;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getType(): AttributeType
    {
        return $this->type;
    }

    public function isBuiltIn(): bool
    {
        return $this->isBuiltIn;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<AttributeOption> */
    public function getOptions(): array
    {
        $options = array_values($this->options->toArray());
        usort($options, static fn (AttributeOption $a, AttributeOption $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        return $options;
    }

    public function rename(string $name): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Attribute name cannot be empty.');
        }

        if (isset($this->name) && $this->name === $name) {
            return;
        }

        if ($this->isBuiltIn && isset($this->name)) {
            throw new \LogicException('Built-in attribute names cannot change.');
        }

        $this->name = $name;
        $this->normalizedName = mb_strtolower($name, 'UTF-8');
        $this->touch();
    }

    public function changeCategory(AttributeCategory $category): void
    {
        if ($this->category === $category) {
            return;
        }
        $this->category = $category;
        $this->touch();
    }

    public function changeDescription(string $description): void
    {
        if ($this->description === $description) {
            return;
        }
        $this->description = $description;
        $this->touch();
    }

    public function changeType(AttributeType $type, bool $used): void
    {
        if ($this->type === $type) {
            return;
        }
        if ($this->isBuiltIn || $used || ($this->type === AttributeType::SELECT && !$this->options->isEmpty())) {
            throw new \LogicException('This attribute type cannot change.');
        }
        $this->type = $type;
        $this->touch();
    }

    public function assertDeletable(): void
    {
        if ($this->isBuiltIn) {
            throw new \LogicException('Built-in attributes cannot be deleted.');
        }
    }

    public function addOption(string $label, int $sortOrder): AttributeOption
    {
        return new AttributeOption($this, $label, $sortOrder);
    }

    public function registerOption(AttributeOption $option): void
    {
        if ($this->type !== AttributeType::SELECT) {
            throw new \LogicException('Only SELECT attributes can have options.');
        }

        if ($option->getDefinition() !== $this || $this->options->contains($option)) {
            throw new \InvalidArgumentException('Option does not belong to this definition or is already registered.');
        }

        $this->assertAvailableOption($option->getLabel(), $option->getSortOrder());
        $this->options->add($option);
        $this->touch();
    }

    public function removeOption(AttributeOption $option): void
    {
        $this->assertOwnedOption($option);
        $this->options->removeElement($option);
        $this->touch();
    }

    public function renameOption(AttributeOption $option, string $label): void
    {
        $this->assertOwnedOption($option);
        $option->updateLabelFromAggregate($label);
    }

    public function moveOption(AttributeOption $option, int $sortOrder): void
    {
        $this->assertOwnedOption($option);
        $option->updateSortOrderFromAggregate($sortOrder);
    }

    public function assertOptionChangeAllowed(AttributeOption $option, string $label, int $sortOrder): void
    {
        $this->assertOwnedOption($option);
        $this->assertAvailableOption($label, $sortOrder, $option);
    }

    public function optionChanged(AttributeOption $option): void
    {
        $this->assertOwnedOption($option);
        $this->touch();
    }

    private function assertOwnedOption(AttributeOption $option): void
    {
        if ($option->getDefinition() !== $this || !$this->options->contains($option)) {
            throw new \InvalidArgumentException('Option does not belong to this definition.');
        }
    }

    private function assertAvailableOption(string $label, int $sortOrder, ?AttributeOption $except = null): void
    {
        if ($label === '' || $sortOrder < 0) {
            throw new \InvalidArgumentException('Option label and position must be valid.');
        }

        foreach ($this->options as $option) {
            if ($option === $except) {
                continue;
            }

            if ($option->getLabel() === $label || $option->getSortOrder() === $sortOrder) {
                throw new \InvalidArgumentException('Option label and position must be unique within a definition.');
            }
        }
    }

    private function touch(): void
    {
        $now = new \DateTimeImmutable();
        $nextPersistedSecond = $this->updatedAt->modify('+1 second');
        $this->updatedAt = $now > $nextPersistedSecond ? $now : $nextPersistedSecond;
    }
}
