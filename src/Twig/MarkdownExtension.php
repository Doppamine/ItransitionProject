<?php

declare(strict_types=1);

namespace App\Twig;

use App\Discussion\MarkdownRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly MarkdownRenderer $renderer)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('safe_markdown', $this->render(...), ['is_safe' => ['html']])];
    }

    public function render(string $source): string
    {
        return $this->renderer->render($source);
    }
}
