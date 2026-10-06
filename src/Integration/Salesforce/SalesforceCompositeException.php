<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

final class SalesforceCompositeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Salesforce Account and Contact creation failed.');
    }
}
