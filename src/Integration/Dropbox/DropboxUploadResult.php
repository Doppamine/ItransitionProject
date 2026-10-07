<?php

declare(strict_types=1);

namespace App\Integration\Dropbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class DropboxUploadResult
{
    public function __construct(public string $id, public string $name, public string $pathDisplay)
    {
    }
}
