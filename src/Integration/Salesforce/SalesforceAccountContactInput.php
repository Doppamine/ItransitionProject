<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SalesforceAccountContactInput
{
    public function __construct(
        public string $companyName,
        public string $lastName,
        public ?string $website = null,
        public ?string $firstName = null,
        public ?string $email = null,
        public ?string $jobTitle = null,
        public ?string $phone = null,
        public ?string $location = null,
    ) {
        if (trim($companyName) === '') {
            throw new \InvalidArgumentException('Company name must not be blank.');
        }
        if (trim($lastName) === '') {
            throw new \InvalidArgumentException('Contact last name must not be blank.');
        }
    }
}
