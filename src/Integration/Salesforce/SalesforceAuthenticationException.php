<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

final class SalesforceAuthenticationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Salesforce authentication failed.');
    }
}
