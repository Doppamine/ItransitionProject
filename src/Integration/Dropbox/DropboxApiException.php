<?php

declare(strict_types=1);

namespace App\Integration\Dropbox;

final class DropboxApiException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dropbox upload failed.');
    }
}
