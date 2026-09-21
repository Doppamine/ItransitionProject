<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AttributeCategory;
use PHPUnit\Framework\TestCase;

final class AttributeCategoryTest extends TestCase
{
    public function testNameIsTrimmedAndNormalizedWithUnicodeLowercase(): void
    {
        $category = new AttributeCategory('  ÉQUIPE  ');

        self::assertSame('ÉQUIPE', $category->getName());
        self::assertSame('équipe', $category->getNormalizedName());

        $category->rename('  SOFT SKILLS  ');

        self::assertSame('SOFT SKILLS', $category->getName());
        self::assertSame('soft skills', $category->getNormalizedName());
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AttributeCategory('  ');
    }
}
