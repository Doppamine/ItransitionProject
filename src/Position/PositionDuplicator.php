<?php

declare(strict_types=1);

namespace App\Position;

use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Enum\AttributeType;

final class PositionDuplicator
{
    public function duplicate(Position $source): Position
    {
        $copy = new Position(mb_substr($source->getTitle(), 0, 248).' — Copy', $source->getShortDescription(), $source->getAccessType(), $source->getMaxProjects());
        foreach ($source->getAttributes() as $attribute) {
            $copy->addAttribute($attribute->getDefinition(), $attribute->getSortOrder());
        }
        foreach ($source->getProjectTags() as $tag) {
            $copy->addProjectTag($tag);
        }
        foreach ($source->getAccessRules() as $rule) {
            $target = new PositionAccessRule($copy, $rule->getDefinition(), $rule->getOperator());
            match ($rule->getDefinition()->getType()) {
                AttributeType::STRING, AttributeType::TEXT => $target->setTextExpected((string) $rule->getTextValue()),
                AttributeType::NUMERIC => $target->setNumericExpected((string) $rule->getNumericValue()),
                AttributeType::DATE => $target->setDateExpected($rule->getDateValue() ?? throw new \LogicException('Incomplete date rule.')),
                AttributeType::PERIOD => $target->setPeriodExpected(
                    $rule->getPeriodStart() ?? throw new \LogicException('Incomplete period rule.'),
                    $rule->getPeriodEnd() ?? throw new \LogicException('Incomplete period rule.'),
                ),
                AttributeType::BOOLEAN => $target->setBooleanExpected($rule->getBooleanValue() ?? throw new \LogicException('Incomplete boolean rule.')),
                AttributeType::SELECT => $target->setOptionExpected($rule->getOption() ?? throw new \LogicException('Incomplete select rule.')),
                AttributeType::IMAGE => throw new \LogicException('Image rules are not supported.'),
            };
        }
        return $copy;
    }
}
