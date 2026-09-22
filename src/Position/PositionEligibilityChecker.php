<?php

declare(strict_types=1);

namespace App\Position;

use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\ProfileAttributeValue;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;

final class PositionEligibilityChecker
{
    public function isEligible(Position $position, Profile $profile): bool
    {
        if ($position->getAccessType() === PositionAccessType::PUBLIC) {
            return true;
        }
        $rules = $position->getAccessRules();
        if ($rules === []) {
            return false;
        }
        foreach ($rules as $rule) {
            $value = $profile->getValueFor($rule->getDefinition());
            if ($value === null || $value->isEmpty() || !$rule->hasExpectedValue() || !$this->passes($rule, $value)) {
                return false;
            }
        }
        return true;
    }

    private function passes(PositionAccessRule $rule, ProfileAttributeValue $value): bool
    {
        if (!AccessRuleOperators::supports($rule->getDefinition()->getType(), $rule->getOperator())) {
            return false;
        }
        $comparison = match ($rule->getDefinition()->getType()) {
            AttributeType::STRING, AttributeType::TEXT => strcmp((string) $value->getTextValue(), (string) $rule->getTextValue()),
            AttributeType::NUMERIC => $this->compareDecimals((string) $value->getNumericValue(), (string) $rule->getNumericValue()),
            AttributeType::DATE => strcmp($value->getDateValue()?->format('Y-m-d') ?? '', $rule->getDateValue()?->format('Y-m-d') ?? ''),
            AttributeType::BOOLEAN => ($value->getBooleanValue() <=> $rule->getBooleanValue()),
            AttributeType::SELECT => $this->sameOption($value, $rule) ? 0 : 1,
            AttributeType::PERIOD => $value->getPeriodStart()?->format('Y-m-d') === $rule->getPeriodStart()?->format('Y-m-d')
                && $value->getPeriodEnd()?->format('Y-m-d') === $rule->getPeriodEnd()?->format('Y-m-d') ? 0 : 1,
            AttributeType::IMAGE => 1,
        };

        return match ($rule->getOperator()) {
            AccessRuleOperator::EQUAL => $comparison === 0,
            AccessRuleOperator::NOT_EQUAL => $comparison !== 0,
            AccessRuleOperator::GREATER_THAN => $comparison > 0,
            AccessRuleOperator::GREATER_THAN_OR_EQUAL => $comparison >= 0,
            AccessRuleOperator::LESS_THAN => $comparison < 0,
            AccessRuleOperator::LESS_THAN_OR_EQUAL => $comparison <= 0,
        };
    }

    private function sameOption(ProfileAttributeValue $value, PositionAccessRule $rule): bool
    {
        $actual = $value->getOption();
        $expected = $rule->getOption();
        return $actual === $expected || ($actual?->getId() !== null && $actual->getId() === $expected?->getId());
    }

    private function compareDecimals(string $left, string $right): int
    {
        [$leftNegative, $leftInteger, $leftFraction] = $this->decimalParts($left);
        [$rightNegative, $rightInteger, $rightFraction] = $this->decimalParts($right);
        if ($leftInteger === '0' && $leftFraction === '') { $leftNegative = false; }
        if ($rightInteger === '0' && $rightFraction === '') { $rightNegative = false; }
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $result = strlen($leftInteger) <=> strlen($rightInteger);
        if ($result === 0) { $result = strcmp($leftInteger, $rightInteger); }
        if ($result === 0) {
            $length = max(strlen($leftFraction), strlen($rightFraction));
            $result = strcmp(str_pad($leftFraction, $length, '0'), str_pad($rightFraction, $length, '0'));
        }
        $result = $result <=> 0;
        return $leftNegative ? -$result : $result;
    }

    /** @return array{bool, string, string} */
    private function decimalParts(string $value): array
    {
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $integer = ltrim($integer, '0');
        return [$negative, $integer === '' ? '0' : $integer, rtrim($fraction, '0')];
    }
}
