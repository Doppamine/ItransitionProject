<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\AttributeOption;
use App\Enum\AttributeType;
use PHPUnit\Framework\TestCase;

final class AttributeDefinitionTest extends TestCase
{
    public function testEditableMetadataTouchesRootAndBuiltInNameAndTypeStayFixed(): void
    {
        $first = new AttributeCategory('First');
        $second = new AttributeCategory('Second');
        $definition = new AttributeDefinition($first, 'Language', AttributeType::STRING);
        $before = $definition->getUpdatedAt();
        $definition->changeCategory($second);
        $definition->changeDescription('Spoken language');
        $definition->changeType(AttributeType::TEXT, false);
        $definition->rename('Languages');
        self::assertSame($second, $definition->getCategory());
        self::assertSame('Spoken language', $definition->getDescription());
        self::assertSame(AttributeType::TEXT, $definition->getType());
        self::assertSame('languages', $definition->getNormalizedName());
        self::assertGreaterThan($before, $definition->getUpdatedAt());

        $builtIn = new AttributeDefinition($first, 'First Name', AttributeType::STRING, isBuiltIn: true);
        $this->expectException(\LogicException::class);
        $builtIn->rename('Alias');
    }

    public function testTypeChangeRejectsUsedBuiltInAndSelectWithOptions(): void
    {
        $category = new AttributeCategory('Skills');
        $used = new AttributeDefinition($category, 'Used', AttributeType::STRING);
        $this->expectException(\LogicException::class);
        $used->changeType(AttributeType::TEXT, true);
    }

    public function testSelectWithOptionsCannotChangeTypeButUnusedCanBecomeSelect(): void
    {
        $category = new AttributeCategory('Skills');
        $definition = new AttributeDefinition($category, 'Language', AttributeType::STRING);
        $definition->changeType(AttributeType::SELECT, false);
        self::assertSame(AttributeType::SELECT, $definition->getType());
        $definition->addOption('English', 10);
        $this->expectException(\LogicException::class);
        $definition->changeType(AttributeType::TEXT, false);
    }

    public function testBuiltInTypeAndDeletionAreProtected(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Identity'), 'First Name', AttributeType::STRING, isBuiltIn: true);
        try {
            $definition->changeType(AttributeType::TEXT, false);
            self::fail('Expected built-in type change to fail.');
        } catch (\LogicException) {
            self::assertSame(AttributeType::STRING, $definition->getType());
        }
        $this->expectException(\LogicException::class);
        $definition->assertDeletable();
    }

    public function testTouchAdvancesAtLeastOnePersistedSecond(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::STRING);
        $future = new \DateTimeImmutable('+1 day');
        (new \ReflectionProperty(AttributeDefinition::class, 'updatedAt'))->setValue($definition, $future);
        $definition->changeDescription('New description');
        self::assertGreaterThanOrEqual($future->getTimestamp() + 1, $definition->getUpdatedAt()->getTimestamp());
    }
    public function testNameIsTrimmedAndUnicodeLowercased(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), '  RÉSUMÉ  ', AttributeType::STRING);

        self::assertSame('RÉSUMÉ', $definition->getName());
        self::assertSame('résumé', $definition->getNormalizedName());
        self::assertSame('', $definition->getDescription());
        self::assertFalse($definition->isBuiltIn());

        $before = $definition->getUpdatedAt();
        $definition->rename('  Professional Résumé  ');

        self::assertSame('Professional Résumé', $definition->getName());
        self::assertSame('professional résumé', $definition->getNormalizedName());
        self::assertGreaterThan($before, $definition->getUpdatedAt());
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AttributeDefinition(new AttributeCategory('Skills'), '  ', AttributeType::STRING);
    }

    public function testNonSelectDefinitionRejectsOptions(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Bio', AttributeType::TEXT);

        $this->expectException(\LogicException::class);

        $definition->addOption('One', 0);
    }

    public function testDirectOptionConstructionCannotBypassSelectRule(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Bio', AttributeType::TEXT);

        $this->expectException(\LogicException::class);

        new AttributeOption($definition, 'One', 0);
    }

    public function testDirectOptionConstructionRegistersWithAggregate(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::SELECT);
        $before = $definition->getUpdatedAt();

        $option = new AttributeOption($definition, 'English', 0);

        self::assertSame([$option], $definition->getOptions());
        self::assertGreaterThan($before, $definition->getUpdatedAt());
    }

    public function testDirectOptionUpdatesCannotBypassAggregateValidationOrTouch(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::SELECT);
        $first = $definition->addOption('English', 0);
        $definition->addOption('French', 1);

        $before = $definition->getUpdatedAt();
        $first->updateLabelFromAggregate('  German  ');
        self::assertSame('German', $first->getLabel());
        self::assertGreaterThan($before, $definition->getUpdatedAt());

        $before = $definition->getUpdatedAt();
        $first->updateSortOrderFromAggregate(2);
        self::assertSame(2, $first->getSortOrder());
        self::assertGreaterThan($before, $definition->getUpdatedAt());

        try {
            $first->updateSortOrderFromAggregate(1);
            self::fail('Expected occupied position to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(2, $first->getSortOrder());
        }
    }

    public function testOptionChangesGoThroughAggregateAndTouchRoot(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::SELECT);
        $before = $definition->getUpdatedAt();

        $second = $definition->addOption('French', 20);
        self::assertGreaterThan($before, $definition->getUpdatedAt());

        $first = $definition->addOption('English', 10);
        self::assertSame([$first, $second], $definition->getOptions());

        $before = $definition->getUpdatedAt();
        $definition->renameOption($first, 'German');
        self::assertSame('German', $first->getLabel());
        self::assertGreaterThan($before, $definition->getUpdatedAt());

        $before = $definition->getUpdatedAt();
        $definition->moveOption($first, 30);
        self::assertSame([$second, $first], $definition->getOptions());
        self::assertGreaterThan($before, $definition->getUpdatedAt());

        $before = $definition->getUpdatedAt();
        $definition->removeOption($second);
        self::assertSame([$first], $definition->getOptions());
        self::assertGreaterThan($before, $definition->getUpdatedAt());
    }

    public function testDuplicateLabelsAndOccupiedOrNegativePositionsAreRejected(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::SELECT);
        $definition->addOption('English', 0);

        foreach ([['French', 0], ['English', 1], ['German', -1]] as [$label, $position]) {
            try {
                $definition->addOption($label, $position);
                self::fail('Expected invalid option to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertCount(1, $definition->getOptions());
            }
        }
    }

    public function testMovingIntoOccupiedPositionIsRejected(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::SELECT);
        $first = $definition->addOption('English', 0);
        $definition->addOption('French', 1);

        $this->expectException(\InvalidArgumentException::class);

        $definition->moveOption($first, 1);
    }

    public function testForeignOptionCannotBeMutated(): void
    {
        $category = new AttributeCategory('Skills');
        $first = new AttributeDefinition($category, 'First', AttributeType::SELECT);
        $second = new AttributeDefinition($category, 'Second', AttributeType::SELECT);
        $option = $first->addOption('English', 0);

        $this->expectException(\InvalidArgumentException::class);

        $second->renameOption($option, 'French');
    }
}
