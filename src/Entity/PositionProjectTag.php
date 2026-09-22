<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_position_project_tag', columns: ['position_id', 'tag_id'])]
#[ORM\Index(name: 'IDX_POSITION_PROJECT_TAG_POSITION', columns: ['position_id'])]
#[ORM\Index(name: 'IDX_POSITION_PROJECT_TAG_TAG', columns: ['tag_id'])]
final class PositionProjectTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Position::class, inversedBy: 'projectTagLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Position $position;

    #[ORM\ManyToOne(targetEntity: Tag::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tag $tag;

    public function __construct(Position $position, Tag $tag)
    {
        $this->position = $position;
        $this->tag = $tag;
    }

    public function getId(): ?int { return $this->id; }
    public function getPosition(): Position { return $this->position; }
    public function getTag(): Tag { return $this->tag; }
}
