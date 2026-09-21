<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_attribute_option_position', columns: ['attribute_definition_id', 'sort_order'])]
#[ORM\UniqueConstraint(name: 'uniq_attribute_option_label', columns: ['attribute_definition_id', 'label'])]
final class AttributeOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttributeDefinition::class, inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'attribute_definition_id', nullable: false, onDelete: 'CASCADE')]
    private AttributeDefinition $definition;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(AttributeDefinition $definition, string $label, int $sortOrder)
    {
        $label = trim($label);

        if ($label === '' || $sortOrder < 0) {
            throw new \InvalidArgumentException('Option label and position must be valid.');
        }

        $this->definition = $definition;
        $this->label = $label;
        $this->sortOrder = $sortOrder;
        $definition->registerOption($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDefinition(): AttributeDefinition
    {
        return $this->definition;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function updateLabelFromAggregate(string $label): void
    {
        $label = trim($label);
        $this->definition->assertOptionChangeAllowed($this, $label, $this->sortOrder);

        if ($this->label === $label) {
            return;
        }

        $this->label = $label;
        $this->definition->optionChanged($this);
    }

    public function updateSortOrderFromAggregate(int $sortOrder): void
    {
        $this->definition->assertOptionChangeAllowed($this, $this->label, $sortOrder);

        if ($this->sortOrder === $sortOrder) {
            return;
        }

        $this->sortOrder = $sortOrder;
        $this->definition->optionChanged($this);
    }
}
