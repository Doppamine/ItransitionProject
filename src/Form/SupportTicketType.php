<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SupportTicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('summary', TextareaType::class, ['label' => 'support.summary', 'trim' => true, 'attr' => ['maxlength' => 2000, 'rows' => 4]])
            ->add('priority', ChoiceType::class, ['label' => 'support.priority', 'placeholder' => false, 'choices' => [
                'support.high' => 'High', 'support.average' => 'Average', 'support.low' => 'Low',
            ]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_token_id' => 'support_ticket']);
    }
}
