<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_attribute_category_normalized_name', columns: ['normalized_name'])]
final class AttributeCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $normalizedName;

    public function __construct(string $name)
    {
        $this->rename($name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function rename(string $name): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $this->name = $name;
        $this->normalizedName = mb_strtolower($name, 'UTF-8');
    }
}
