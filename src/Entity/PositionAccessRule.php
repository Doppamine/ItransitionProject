<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Position\AccessRuleOperators;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'IDX_POSITION_ACCESS_RULE_POSITION', columns: ['position_id'])]
#[ORM\Index(name: 'IDX_POSITION_ACCESS_RULE_DEFINITION', columns: ['attribute_definition_id'])]
#[ORM\Index(name: 'IDX_POSITION_ACCESS_RULE_OPTION', columns: ['option_id'])]
final class PositionAccessRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Position::class, inversedBy: 'accessRules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Position $position;

    #[ORM\ManyToOne(targetEntity: AttributeDefinition::class)]
    #[ORM\JoinColumn(name: 'attribute_definition_id', nullable: false, onDelete: 'CASCADE')]
    private AttributeDefinition $definition;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: AccessRuleOperator::class)]
    private AccessRuleOperator $operator;

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

    public function __construct(Position $position, AttributeDefinition $definition, AccessRuleOperator $operator)
    {
        if (!AccessRuleOperators::supports($definition->getType(), $operator)) {
            throw new \InvalidArgumentException('This operator is not available for the selected attribute type.');
        }
        $this->position = $position;
        $this->definition = $definition;
        $this->operator = $operator;
        $position->registerAccessRule($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getPosition(): Position { return $this->position; }
    public function getDefinition(): AttributeDefinition { return $this->definition; }
    public function getOperator(): AccessRuleOperator { return $this->operator; }
    public function getTextValue(): ?string { return $this->textValue; }
    public function getNumericValue(): ?string { return $this->numericValue; }
    public function getDateValue(): ?\DateTimeImmutable { return $this->dateValue; }
    public function getPeriodStart(): ?\DateTimeImmutable { return $this->periodStart; }
    public function getPeriodEnd(): ?\DateTimeImmutable { return $this->periodEnd; }
    public function getBooleanValue(): ?bool { return $this->booleanValue; }
    public function getOption(): ?AttributeOption { return $this->option; }

    public function setTextExpected(string $value): void
    {
        $this->assertType(AttributeType::STRING, AttributeType::TEXT);
        if (trim($value) === '') {
            throw new \InvalidArgumentException('Expected value cannot be empty.');
        }
        $this->clearExpected();
        $this->textValue = $value;
        $this->position->ruleChanged($this);
    }

    public function setNumericExpected(string $value): void
    {
        $this->assertType(AttributeType::NUMERIC);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new \InvalidArgumentException('Expected numeric value must be a decimal string.');
        }
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        if (strlen(ltrim($integer, '0')) > 14 || strlen($fraction) > 6) {
            throw new \InvalidArgumentException('Expected numeric value exceeds precision or scale.');
        }
        $this->clearExpected();
        $this->numericValue = $value;
        $this->position->ruleChanged($this);
    }

    public function setDateExpected(\DateTimeImmutable $value): void
    {
        $this->assertType(AttributeType::DATE);
        $this->clearExpected();
        $this->dateValue = $value;
        $this->position->ruleChanged($this);
    }

    public function setPeriodExpected(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $this->assertType(AttributeType::PERIOD);
        if ($start->format('Y-m-d') > $end->format('Y-m-d')) {
            throw new \InvalidArgumentException('Expected period start must not be after its end.');
        }
        $this->clearExpected();
        $this->periodStart = $start;
        $this->periodEnd = $end;
        $this->position->ruleChanged($this);
    }

    public function setBooleanExpected(bool $value): void
    {
        $this->assertType(AttributeType::BOOLEAN);
        $this->clearExpected();
        $this->booleanValue = $value;
        $this->position->ruleChanged($this);
    }

    public function setOptionExpected(AttributeOption $option): void
    {
        $this->assertType(AttributeType::SELECT);
        if ($option->getDefinition() !== $this->definition) {
            throw new \InvalidArgumentException('Expected option does not belong to the selected AttributeDefinition.');
        }
        $this->clearExpected();
        $this->option = $option;
        $this->position->ruleChanged($this);
    }

    public function hasExpectedValue(): bool
    {
        return match ($this->definition->getType()) {
            AttributeType::STRING, AttributeType::TEXT => $this->textValue !== null,
            AttributeType::NUMERIC => $this->numericValue !== null,
            AttributeType::DATE => $this->dateValue !== null,
            AttributeType::PERIOD => $this->periodStart !== null && $this->periodEnd !== null,
            AttributeType::BOOLEAN => $this->booleanValue !== null,
            AttributeType::SELECT => $this->option !== null,
            AttributeType::IMAGE => false,
        };
    }

    private function assertType(AttributeType ...$types): void
    {
        if (!in_array($this->definition->getType(), $types, true)) {
            throw new \LogicException('Expected value does not match the AttributeDefinition type.');
        }
    }

    private function clearExpected(): void
    {
        $this->textValue = null;
        $this->numericValue = null;
        $this->dateValue = null;
        $this->periodStart = null;
        $this->periodEnd = null;
        $this->booleanValue = null;
        $this->option = null;
    }
}
