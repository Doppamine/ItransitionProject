<?php

declare(strict_types=1);

namespace App\Twig;

use App\Image\CloudinaryImages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CloudinaryExtension extends AbstractExtension
{
    public function __construct(private readonly CloudinaryImages $images)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cloudinary_image_url', $this->images->url(...))];
    }
}
