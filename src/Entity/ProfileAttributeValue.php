<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttributeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_profile_attribute_definition', columns: ['profile_id', 'attribute_definition_id'])]
final class ProfileAttributeValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Profile::class, inversedBy: 'values')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Profile $profile;

    #[ORM\ManyToOne(targetEntity: AttributeDefinition::class)]
    #[ORM\JoinColumn(name: 'attribute_definition_id', nullable: false, onDelete: 'CASCADE')]
    private AttributeDefinition $definition;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $textValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $numericValue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodEnd = null;

    #[ORM\Column(nullable: true)]
    private ?bool $booleanValue = null;

    #[ORM\ManyToOne(targetEntity: AttributeOption::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: true)]
    private ?AttributeOption $option = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageKey = null;

    public function __construct(Profile $profile, AttributeDefinition $definition)
    {
        $this->profile = $profile;
        $this->definition = $definition;
        $profile->registerValue($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfile(): Profile
    {
        return $this->profile;
    }

    public function getDefinition(): AttributeDefinition
    {
        return $this->definition;
    }

    public function getTextValue(): ?string
    {
        return $this->textValue;
    }

    public function getNumericValue(): ?string
    {
        return $this->numericValue;
    }

    public function getDateValue(): ?\DateTimeImmutable
    {
        return $this->dateValue;
    }

    public function getPeriodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function getBooleanValue(): ?bool
    {
        return $this->booleanValue;
    }

    public function getOption(): ?AttributeOption
    {
        return $this->option;
    }

    public function getImageKey(): ?string
    {
        return $this->imageKey;
    }

    public function isEmpty(): bool
    {
        return $this->textValue === null
            && $this->numericValue === null
            && $this->dateValue === null
            && $this->periodStart === null
            && $this->periodEnd === null
            && $this->booleanValue === null
            && $this->option === null
            && $this->imageKey === null;
    }

    public function setText(string $text): void
    {
        $this->assertType(AttributeType::STRING, AttributeType::TEXT);
        $this->clearStoredValue();
        $this->textValue = $text;
        $this->profile->valueChanged($this);
    }

    public function setNumeric(string $decimal): void
    {
        $this->assertType(AttributeType::NUMERIC);

        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $decimal)) {
            throw new \InvalidArgumentException('Numeric value must be a decimal string.');
        }

        [$integer, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');

        if (strlen(ltrim($integer, '0')) > 14 || strlen($fraction) > 6) {
            throw new \InvalidArgumentException('Numeric value exceeds the configured precision or scale.');
        }

        $this->clearStoredValue();
        $this->numericValue = $decimal;
        $this->profile->valueChanged($this);
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->assertType(AttributeType::DATE);
        $this->clearStoredValue();
        $this->dateValue = $date;
        $this->profile->valueChanged($this);
    }

    public function setPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $this->assertType(AttributeType::PERIOD);

        if ($start->format('Y-m-d') > $end->format('Y-m-d')) {
            throw new \InvalidArgumentException('Period start must not be after period end.');
        }

        $this->clearStoredValue();
        $this->periodStart = $start;
        $this->periodEnd = $end;
        $this->profile->valueChanged($this);
    }

    public function setBoolean(bool $boolean): void
    {
        $this->assertType(AttributeType::BOOLEAN);
        $this->clearStoredValue();
        $this->booleanValue = $boolean;
        $this->profile->valueChanged($this);
    }

    public function setOption(AttributeOption $option): void
    {
        $this->assertType(AttributeType::SELECT);

        if ($option->getDefinition() !== $this->definition) {
            throw new \InvalidArgumentException('Option does not belong to this AttributeDefinition.');
        }

        $this->clearStoredValue();
        $this->option = $option;
        $this->profile->valueChanged($this);
    }

    public function setImageKey(string $key): void
    {
        $this->assertType(AttributeType::IMAGE);

        if (trim($key) === '') {
            throw new \InvalidArgumentException('Image key cannot be blank.');
        }

        $this->clearStoredValue();
        $this->imageKey = $key;
        $this->profile->valueChanged($this);
    }

    public function clearValue(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $this->clearStoredValue();
        $this->profile->valueChanged($this);
    }

    private function assertType(AttributeType ...$types): void
    {
        if (!in_array($this->definition->getType(), $types, true)) {
            throw new \LogicException('Value representation does not match AttributeDefinition type.');
        }
    }

    private function clearStoredValue(): void
    {
        $this->textValue = null;
        $this->numericValue = null;
        $this->dateValue = null;
        $this->periodStart = null;
        $this->periodEnd = null;
        $this->booleanValue = null;
        $this->option = null;
        $this->imageKey = null;
    }
}
