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
            $operators['enum.operator.'.$operator->value] = $operator->value;
        }
        $builder->add('operator', ChoiceType::class, ['label' => 'form.operator', 'choices' => $operators])->add('version', HiddenType::class);
        match ($definition->getType()) {
            AttributeType::STRING => $builder->add('textValue', TextType::class, ['label' => 'form.expected_value']),
            AttributeType::TEXT => $builder->add('textValue', TextareaType::class, ['label' => 'form.expected_value']),
            AttributeType::NUMERIC => $builder->add('numericValue', TextType::class, ['label' => 'form.expected_number', 'attr' => ['inputmode' => 'decimal']]),
            AttributeType::DATE => $builder->add('dateValue', DateType::class, ['label' => 'form.expected_date', 'widget' => 'single_text', 'input' => 'datetime_immutable']),
            AttributeType::PERIOD => $builder
                ->add('periodStart', DateType::class, ['label' => 'form.expected_start', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
                ->add('periodEnd', DateType::class, ['label' => 'form.expected_end', 'widget' => 'single_text', 'input' => 'datetime_immutable']),
            AttributeType::BOOLEAN => $builder->add('booleanValue', ChoiceType::class, ['label' => 'form.expected_value', 'choices' => ['common.yes' => true, 'common.no' => false]]),
            AttributeType::SELECT => $builder->add('option', ChoiceType::class, ['label' => 'form.expected_option', 'choices' => $this->optionChoices($definition), 'choice_translation_domain' => false]),
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
