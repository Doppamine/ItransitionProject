<?php

declare(strict_types=1);

namespace App\Tests\Position;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Tag;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use App\Position\PositionDuplicator;
use PHPUnit\Framework\TestCase;

final class PositionDuplicatorTest extends TestCase
{
    public function testDuplicateCopiesEveryConfiguredPartIntoANewAggregate(): void
    {
        $category = new AttributeCategory('Position');
        $text = new AttributeDefinition($category, 'Summary', AttributeType::TEXT);
        $period = new AttributeDefinition($category, 'Availability', AttributeType::PERIOD);
        $tag = new Tag('Symfony');
        $source = new Position('Backend Developer', 'Build APIs', PositionAccessType::RESTRICTED, 3);
        $source->addAttribute($text, 20);
        $source->addProjectTag($tag);
        (new PositionAccessRule($source, $period, AccessRuleOperator::EQUAL))
            ->setPeriodExpected(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        $copy = (new PositionDuplicator())->duplicate($source);

        self::assertNotSame($source, $copy);
        self::assertSame('Backend Developer — Copy', $copy->getTitle());
        self::assertSame('Build APIs', $copy->getShortDescription());
        self::assertSame(PositionAccessType::RESTRICTED, $copy->getAccessType());
        self::assertSame(3, $copy->getMaxProjects());
        self::assertCount(1, $copy->getAttributes());
        self::assertNotSame($source->getAttributes()[0], $copy->getAttributes()[0]);
        self::assertSame($text, $copy->getAttributes()[0]->getDefinition());
        self::assertSame(20, $copy->getAttributes()[0]->getSortOrder());
        self::assertSame([$tag], $copy->getProjectTags());
        self::assertCount(1, $copy->getAccessRules());
        self::assertNotSame($source->getAccessRules()[0], $copy->getAccessRules()[0]);
        self::assertSame('2026-01-01', $copy->getAccessRules()[0]->getPeriodStart()?->format('Y-m-d'));
        self::assertSame('2026-12-31', $copy->getAccessRules()[0]->getPeriodEnd()?->format('Y-m-d'));
        self::assertNull($copy->getId());
        self::assertNull($copy->getVersion());

        $longCopy = (new PositionDuplicator())->duplicate(new Position(str_repeat('A', 255), '', PositionAccessType::PUBLIC, 0));
        self::assertSame(255, mb_strlen($longCopy->getTitle()));
        self::assertStringEndsWith(' — Copy', $longCopy->getTitle());
    }
}
