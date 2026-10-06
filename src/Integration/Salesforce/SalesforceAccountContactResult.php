<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SalesforceAccountContactResult
{
    public function __construct(public string $accountId, public string $contactId)
    {
    }
}
