<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\AttributeType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AttributeDefinitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'ui.name', 'disabled' => $options['built_in'], 'attr' => ['maxlength' => 255]])
            ->add('category', ChoiceType::class, ['label' => 'ui.category', 'choices' => $options['categories'], 'choice_translation_domain' => false])
            ->add('description', TextareaType::class, ['label' => 'form.description', 'required' => false])
            ->add('type', ChoiceType::class, [
                'label' => 'ui.type',
                'disabled' => $options['type_fixed'],
                'choices' => [
                    'enum.type.string' => AttributeType::STRING->value,
                    'enum.type.text' => AttributeType::TEXT->value,
                    'enum.type.image' => AttributeType::IMAGE->value,
                    'enum.type.numeric' => AttributeType::NUMERIC->value,
                    'enum.type.date' => AttributeType::DATE->value,
                    'enum.type.period' => AttributeType::PERIOD->value,
                    'enum.type.boolean' => AttributeType::BOOLEAN->value,
                    'enum.type.select' => AttributeType::SELECT->value,
                ],
            ]);
        if ($options['editing']) {
            $builder->add('version', HiddenType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'categories' => [],
            'built_in' => false,
            'type_fixed' => false,
            'editing' => false,
            'csrf_protection' => true,
            'csrf_token_id' => 'attribute_definition',
        ]);
        $resolver->setAllowedTypes('categories', 'array');
        $resolver->setAllowedTypes('built_in', 'bool');
        $resolver->setAllowedTypes('type_fixed', 'bool');
        $resolver->setAllowedTypes('editing', 'bool');
    }
}
