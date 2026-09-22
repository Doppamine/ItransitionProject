<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use App\Repository\ProfileRepository;

#[ORM\Entity(repositoryClass: ProfileRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_profile_user', columns: ['user_id'])]
final class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $version = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProfileAttributeValue> */
    #[ORM\OneToMany(mappedBy: 'profile', targetEntity: ProfileAttributeValue::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $values;

    private function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->values = new ArrayCollection();
    }

    /** @param iterable<AttributeDefinition> $builtIns */
    public static function createWithBuiltIns(User $user, iterable $builtIns): self
    {
        $profile = new self($user);

        foreach ($builtIns as $definition) {
            if (!$definition->isBuiltIn()) {
                throw new \InvalidArgumentException('Profile initialization requires built-in definitions.');
            }

            $profile->selectAttribute($definition);
        }

        return $profile;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
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

    /** @return list<ProfileAttributeValue> */
    public function getValues(): array
    {
        return array_values($this->values->toArray());
    }

    public function getValueFor(AttributeDefinition $definition): ?ProfileAttributeValue
    {
        foreach ($this->values as $value) {
            if ($this->sameDefinition($value->getDefinition(), $definition)) {
                return $value;
            }
        }

        return null;
    }

    public function selectAttribute(AttributeDefinition $definition): ProfileAttributeValue
    {
        return $this->getValueFor($definition)
            ?? $this->restoreRemovedValue($definition)
            ?? new ProfileAttributeValue($this, $definition);
    }

    public function removeAttribute(AttributeDefinition $definition): void
    {
        $value = $this->getValueFor($definition);

        if ($value === null) {
            throw new \InvalidArgumentException('Attribute is not selected.');
        }

        if ($value->getDefinition()->isBuiltIn()) {
            throw new \LogicException('Built-in attributes cannot be removed from a Profile.');
        }

        $this->values->removeElement($value);
        $this->touch();
    }

    public function registerValue(ProfileAttributeValue $value): void
    {
        if ($value->getProfile() !== $this || $this->getValueFor($value->getDefinition()) !== null) {
            throw new \InvalidArgumentException('Attribute value does not belong to this Profile or is already selected.');
        }

        $this->values->add($value);
        $this->touch();
    }

    public function valueChanged(ProfileAttributeValue $value): void
    {
        if (!$this->values->contains($value)) {
            throw new \InvalidArgumentException('Attribute value does not belong to this Profile.');
        }

        $this->touch();
    }

    private function sameDefinition(AttributeDefinition $left, AttributeDefinition $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }

    private function restoreRemovedValue(AttributeDefinition $definition): ?ProfileAttributeValue
    {
        if (!$this->values instanceof PersistentCollection) {
            return null;
        }

        foreach ($this->values->getSnapshot() as $removed) {
            if ($this->values->contains($removed) || !$this->sameDefinition($removed->getDefinition(), $definition)) {
                continue;
            }

            $this->values->add($removed);

            if ($removed->isEmpty()) {
                $this->touch();
            } else {
                $removed->clearValue();
            }

            return $removed;
        }

        return null;
    }

    private function touch(): void
    {
        $now = new \DateTimeImmutable();
        $nextPersistedSecond = $this->updatedAt->modify('+1 second');
        $this->updatedAt = $now > $nextPersistedSecond ? $now : $nextPersistedSecond;
    }
}
