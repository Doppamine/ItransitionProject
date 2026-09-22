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
            ->add('name', TextType::class, ['label' => 'Name', 'disabled' => $options['built_in'], 'attr' => ['maxlength' => 255]])
            ->add('category', ChoiceType::class, ['label' => 'Category', 'choices' => $options['categories']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'disabled' => $options['type_fixed'],
                'choices' => [
                    'String' => AttributeType::STRING->value,
                    'Text' => AttributeType::TEXT->value,
                    'Image' => AttributeType::IMAGE->value,
                    'Numeric' => AttributeType::NUMERIC->value,
                    'Date' => AttributeType::DATE->value,
                    'Period' => AttributeType::PERIOD->value,
                    'Boolean' => AttributeType::BOOLEAN->value,
                    'One-of-many / Select' => AttributeType::SELECT->value,
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
