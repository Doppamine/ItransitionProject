<?php

declare(strict_types=1);

namespace App\CV;

use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\ProfileAttributeValue;
use App\Enum\AttributeType;

final class CVAttributes
{
    private const BUILT_IN_NAMES = ['first name', 'last name', 'location', 'personal photo'];

    /** @param list<AttributeDefinition> $definitions
     *  @return list<array{definition: AttributeDefinition, value: ?ProfileAttributeValue, missing: bool, display: string}>
     */
    public function builtIns(Profile $profile, Position $position, array $definitions): array
    {
        $byName = [];
        foreach ($definitions as $definition) {
            if ($definition->isBuiltIn()) {
                $byName[$definition->getNormalizedName()] = $definition;
            }
        }
        $ordered = [];
        foreach (self::BUILT_IN_NAMES as $name) {
            if (isset($byName[$name])) {
                $ordered[] = $this->item($profile, $byName[$name]);
            }
        }
        return $ordered;
    }

    /** @param list<AttributeDefinition> $builtIns
     *  @return list<array{definition: AttributeDefinition, value: ?ProfileAttributeValue, missing: bool, display: string}>
     */
    public function position(Profile $profile, Position $position, array $builtIns): array
    {
        $builtInIds = [];
        $builtInObjects = [];
        foreach ($this->builtIns($profile, $position, $builtIns) as $item) {
            $builtInIds[$item['definition']->getId()] = true;
            $builtInObjects[spl_object_id($item['definition'])] = true;
        }

        $items = [];
        foreach ($position->getAttributes() as $attribute) {
            $definition = $attribute->getDefinition();
            if (($definition->getId() !== null && isset($builtInIds[$definition->getId()])) || isset($builtInObjects[spl_object_id($definition)])) {
                continue;
            }
            $items[] = $this->item($profile, $definition);
        }
        return $items;
    }

    /** @param list<AttributeDefinition> $builtIns */
    public function isComplete(Profile $profile, Position $position, array $builtIns): bool
    {
        foreach (array_merge($this->builtIns($profile, $position, $builtIns), $this->position($profile, $position, $builtIns)) as $item) {
            if ($item['missing']) {
                return false;
            }
        }
        return true;
    }

    /** @return array{definition: AttributeDefinition, value: ?ProfileAttributeValue, missing: bool, display: string} */
    private function item(Profile $profile, AttributeDefinition $definition): array
    {
        $value = $profile->getValueFor($definition);
        $missing = $value === null || $value->isEmpty() || match ($definition->getType()) {
            AttributeType::STRING, AttributeType::TEXT => trim((string) $value->getTextValue()) === '',
            AttributeType::PERIOD => $value->getPeriodStart() === null || $value->getPeriodEnd() === null,
            AttributeType::IMAGE => trim((string) $value->getImageKey()) === '',
            default => false,
        };

        return [
            'definition' => $definition,
            'value' => $value,
            'missing' => $missing,
            'display' => $missing ? '' : $this->display($definition->getType(), $value),
        ];
    }

    private function display(AttributeType $type, ProfileAttributeValue $value): string
    {
        return match ($type) {
            AttributeType::STRING, AttributeType::TEXT => (string) $value->getTextValue(),
            AttributeType::NUMERIC => (string) $value->getNumericValue(),
            AttributeType::DATE => $value->getDateValue()?->format('Y-m-d') ?? '',
            AttributeType::PERIOD => ($value->getPeriodStart()?->format('Y-m-d') ?? '').' – '.($value->getPeriodEnd()?->format('Y-m-d') ?? ''),
            AttributeType::BOOLEAN => $value->getBooleanValue() ? 'Yes' : 'No',
            AttributeType::SELECT => $value->getOption()?->getLabel() ?? '',
            AttributeType::IMAGE => 'Photo on file; preview is unavailable.',
        };
    }
}
