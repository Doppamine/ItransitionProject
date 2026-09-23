<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DiscussionPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscussionPostRepository::class)]
#[ORM\Table(name: 'discussion_post')]
#[ORM\Index(name: 'idx_discussion_post_position_order', columns: ['position_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_discussion_post_position_incremental', columns: ['position_id', 'id'])]
final class DiscussionPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Position $position;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_user_id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Position $position, User $author, string $content)
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new \InvalidArgumentException('Enter a post of at most 10000 characters.');
        }
        $this->position = $position;
        $this->author = $author;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPosition(): Position { return $this->position; }
    public function getAuthor(): User { return $this->author; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
