<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_position_attribute_definition', columns: ['position_id', 'attribute_definition_id'])]
#[ORM\Index(name: 'IDX_POSITION_ATTRIBUTE_POSITION', columns: ['position_id'])]
#[ORM\Index(name: 'IDX_POSITION_ATTRIBUTE_DEFINITION', columns: ['attribute_definition_id'])]
final class PositionAttribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Position::class, inversedBy: 'attributes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Position $position;

    #[ORM\ManyToOne(targetEntity: AttributeDefinition::class)]
    #[ORM\JoinColumn(name: 'attribute_definition_id', nullable: false, onDelete: 'CASCADE')]
    private AttributeDefinition $definition;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(Position $position, AttributeDefinition $definition, int $sortOrder)
    {
        if ($sortOrder < 0) {
            throw new \InvalidArgumentException('Attribute position cannot be negative.');
        }
        $this->position = $position;
        $this->definition = $definition;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): ?int { return $this->id; }
    public function getPosition(): Position { return $this->position; }
    public function getDefinition(): AttributeDefinition { return $this->definition; }
    public function getSortOrder(): int { return $this->sortOrder; }

    public function changeSortOrder(int $sortOrder): void
    {
        if ($sortOrder < 0) {
            throw new \InvalidArgumentException('Attribute position cannot be negative.');
        }
        $this->sortOrder = $sortOrder;
    }
}
