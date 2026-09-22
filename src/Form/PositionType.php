<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PositionAccessType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PositionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['attr' => ['maxlength' => 255]])
            ->add('shortDescription', TextareaType::class, ['label' => 'Short description', 'required' => false, 'attr' => ['maxlength' => 2000, 'rows' => 4]])
            ->add('accessType', ChoiceType::class, ['label' => 'Access', 'choices' => ['Public' => PositionAccessType::PUBLIC->value, 'Restricted' => PositionAccessType::RESTRICTED->value]])
            ->add('maxProjects', IntegerType::class, ['label' => 'Maximum projects', 'attr' => ['min' => 0]]);
        if ($options['editing']) {
            $builder->add('version', HiddenType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['editing' => false, 'csrf_token_id' => 'position']);
        $resolver->setAllowedTypes('editing', 'bool');
    }
}
