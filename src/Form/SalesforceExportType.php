<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SalesforceExportType extends AbstractType
{
    public const MAX_LENGTHS = ['companyName' => 255, 'jobTitle' => 128, 'phone' => 40, 'website' => 255, 'firstName' => 40, 'lastName' => 80, 'location' => 40];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('companyName', TextType::class, ['label' => 'salesforce.company_name', 'attr' => ['maxlength' => self::MAX_LENGTHS['companyName']]])
            ->add('jobTitle', TextType::class, ['label' => 'salesforce.job_title', 'attr' => ['maxlength' => self::MAX_LENGTHS['jobTitle']]])
            ->add('phone', TextType::class, ['label' => 'salesforce.phone', 'attr' => ['maxlength' => self::MAX_LENGTHS['phone']]])
            ->add('website', TextType::class, ['label' => 'salesforce.website', 'attr' => ['maxlength' => self::MAX_LENGTHS['website']]]);
        if ($options['needs_contact_details']) {
            $builder
                ->add('firstName', TextType::class, ['label' => 'salesforce.contact_first_name', 'attr' => ['maxlength' => self::MAX_LENGTHS['firstName']]])
                ->add('lastName', TextType::class, ['label' => 'salesforce.contact_last_name', 'attr' => ['maxlength' => self::MAX_LENGTHS['lastName']]])
                ->add('location', TextType::class, ['label' => 'salesforce.contact_location', 'attr' => ['maxlength' => self::MAX_LENGTHS['location']]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['needs_contact_details' => true, 'csrf_protection' => true, 'csrf_token_id' => 'salesforce_export']);
        $resolver->setAllowedTypes('needs_contact_details', 'bool');
    }
}
