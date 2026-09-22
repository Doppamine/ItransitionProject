<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Project name', 'attr' => ['maxlength' => 255]])
            ->add('startDate', DateType::class, ['label' => 'Start date', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('endDate', DateType::class, ['label' => 'End date', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('description', TextareaType::class, ['label' => 'Description (Markdown)', 'required' => false, 'attr' => ['rows' => 7, 'maxlength' => 10000]])
            ->add('tags', TextType::class, ['label' => 'Technology tags', 'required' => false, 'attr' => ['maxlength' => 2500, 'autocomplete' => 'off']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_token_id' => 'candidate_project']);
    }
}
