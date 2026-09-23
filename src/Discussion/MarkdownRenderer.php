<?php

declare(strict_types=1);

namespace App\Discussion;

use League\CommonMark\CommonMarkConverter;

final class MarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function render(string $source): string
    {
        return $this->converter->convert($source)->getContent();
    }
}
