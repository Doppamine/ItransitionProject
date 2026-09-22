<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AttributeDefinition;
use App\Enum\AttributeType;
use App\Position\AccessRuleOperators;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositionAccessRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var AttributeDefinition $definition */
        $definition = $options['definition'];
        $operators = [];
        foreach (AccessRuleOperators::for($definition->getType()) as $operator) {
            $operators[$operator->label()] = $operator->value;
        }
        $builder->add('operator', ChoiceType::class, ['choices' => $operators])->add('version', HiddenType::class);
        match ($definition->getType()) {
            AttributeType::STRING => $builder->add('textValue', TextType::class, ['label' => 'Expected value']),
            AttributeType::TEXT => $builder->add('textValue', TextareaType::class, ['label' => 'Expected value']),
            AttributeType::NUMERIC => $builder->add('numericValue', TextType::class, ['label' => 'Expected number', 'attr' => ['inputmode' => 'decimal']]),
            AttributeType::DATE => $builder->add('dateValue', DateType::class, ['label' => 'Expected date', 'widget' => 'single_text', 'input' => 'datetime_immutable']),
            AttributeType::PERIOD => $builder
                ->add('periodStart', DateType::class, ['label' => 'Expected start', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
                ->add('periodEnd', DateType::class, ['label' => 'Expected end', 'widget' => 'single_text', 'input' => 'datetime_immutable']),
            AttributeType::BOOLEAN => $builder->add('booleanValue', ChoiceType::class, ['label' => 'Expected value', 'choices' => ['Yes' => true, 'No' => false]]),
            AttributeType::SELECT => $builder->add('option', ChoiceType::class, ['label' => 'Expected option', 'choices' => $this->optionChoices($definition)]),
            AttributeType::IMAGE => null,
        };
    }

    /** @return array<string, string> */
    private function optionChoices(AttributeDefinition $definition): array
    {
        $choices = [];
        foreach ($definition->getOptions() as $option) {
            $choices[$option->getLabel()] = (string) $option->getId();
        }
        return $choices;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('definition');
        $resolver->setAllowedTypes('definition', AttributeDefinition::class);
        $resolver->setDefaults(['csrf_token_id' => 'position_access_rule']);
    }
}
