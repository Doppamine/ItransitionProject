<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class SystemLabelExtension extends AbstractExtension
{
    private const ATTRIBUTE_KEYS = [
        'first name' => 'builtin_attribute.first_name',
        'last name' => 'builtin_attribute.last_name',
        'location' => 'builtin_attribute.location',
        'personal photo' => 'builtin_attribute.personal_photo',
    ];

    private const CATEGORY_KEYS = [
        'certification' => 'seeded_category.certification',
        'domain knowledge' => 'seeded_category.domain_knowledge',
        'personal information' => 'seeded_category.personal_information',
        'soft skills' => 'seeded_category.soft_skills',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('attribute_label', $this->attributeLabel(...)),
            new TwigFilter('category_label', $this->categoryLabel(...)),
        ];
    }

    /** @param AttributeDefinition|array<string, mixed> $definition */
    public function attributeLabel(AttributeDefinition|array $definition): string
    {
        if ($definition instanceof AttributeDefinition) {
            $name = $definition->getName();
            $builtIn = $definition->isBuiltIn();
            $normalizedName = $definition->getNormalizedName();
        } else {
            $name = (string) ($definition['name'] ?? '');
            $builtIn = in_array($definition['is_built_in'] ?? false, [true, 1, '1', 't'], true);
            $normalizedName = (string) ($definition['normalized_name'] ?? '');
        }

        $key = $builtIn ? (self::ATTRIBUTE_KEYS[$normalizedName] ?? null) : null;
        return $key === null ? $name : $this->translator->trans($key);
    }

    public function categoryLabel(AttributeCategory|string $category): string
    {
        $name = $category instanceof AttributeCategory ? $category->getName() : $category;
        $normalizedName = $category instanceof AttributeCategory ? $category->getNormalizedName() : mb_strtolower(trim($name), 'UTF-8');
        $key = self::CATEGORY_KEYS[$normalizedName] ?? null;
        return $key === null ? $name : $this->translator->trans($key);
    }
}
