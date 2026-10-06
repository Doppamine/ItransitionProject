<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

final class SalesforceApiException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Salesforce API request failed.');
    }
}
