<?php

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use App\Profile\ProfileValueMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileValueMapperTest extends TestCase
{
    #[DataProvider('scalarCases')]
    public function testMapsScalarTypes(AttributeType $type, mixed $input, string $getter, mixed $expected): void
    {
        $value = $this->value($type);

        (new ProfileValueMapper())->apply($value, $input);

        $actual = $value->$getter();
        self::assertSame($expected, $actual instanceof \DateTimeImmutable ? $actual->format('Y-m-d') : $actual);
    }

    public static function scalarCases(): iterable
    {
        yield 'string' => [AttributeType::STRING, 'Ada', 'getTextValue', 'Ada'];
        yield 'text' => [AttributeType::TEXT, '**Markdown**', 'getTextValue', '**Markdown**'];
        yield 'numeric' => [AttributeType::NUMERIC, '12.50', 'getNumericValue', '12.50'];
        yield 'date' => [AttributeType::DATE, '2026-09-22', 'getDateValue', '2026-09-22'];
        yield 'boolean yes' => [AttributeType::BOOLEAN, 'true', 'getBooleanValue', true];
        yield 'boolean no' => [AttributeType::BOOLEAN, 'false', 'getBooleanValue', false];
    }

    public function testMapsPeriodAndCanClearIt(): void
    {
        $value = $this->value(AttributeType::PERIOD);
        $mapper = new ProfileValueMapper();

        $mapper->apply($value, ['start' => '2025-01-01', 'end' => '2025-12-31']);
        self::assertSame('2025-01-01', $value->getPeriodStart()?->format('Y-m-d'));
        self::assertSame('2025-12-31', $value->getPeriodEnd()?->format('Y-m-d'));

        $mapper->apply($value, ['start' => '', 'end' => '']);
        self::assertTrue($value->isEmpty());
    }

    public function testBlankAndUnsetValuesClear(): void
    {
        $mapper = new ProfileValueMapper();
        $text = $this->value(AttributeType::STRING);
        $mapper->apply($text, 'Ada');
        $mapper->apply($text, '');
        self::assertTrue($text->isEmpty());

        $boolean = $this->value(AttributeType::BOOLEAN);
        $mapper->apply($boolean, 'false');
        $mapper->apply($boolean, '');
        self::assertTrue($boolean->isEmpty());
    }

    public function testIncompletePeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProfileValueMapper())->apply($this->value(AttributeType::PERIOD), ['start' => '2025-01-01', 'end' => '']);
    }

    public function testWrongSelectOptionIsRejected(): void
    {
        $category = new AttributeCategory('Skills');
        $selected = new AttributeDefinition($category, 'Language', AttributeType::SELECT);
        $foreign = new AttributeDefinition($category, 'Level', AttributeType::SELECT);
        $option = $foreign->addOption('Expert', 0);
        (new \ReflectionProperty($option, 'id'))->setValue($option, 9);
        $profile = Profile::createWithBuiltIns(new User('candidate@example.test'), []);

        $this->expectException(\InvalidArgumentException::class);
        (new ProfileValueMapper())->apply($profile->selectAttribute($selected), '9');
    }

    public function testSelectOptionMapsAndClears(): void
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Language', AttributeType::SELECT);
        $option = $definition->addOption('English', 0);
        (new \ReflectionProperty($option, 'id'))->setValue($option, 7);
        $value = Profile::createWithBuiltIns(new User('candidate@example.test'), [])->selectAttribute($definition);
        $mapper = new ProfileValueMapper();

        $mapper->apply($value, '7');
        self::assertSame($option, $value->getOption());
        $mapper->apply($value, '');
        self::assertTrue($value->isEmpty());
    }

    public function testImageMutationIsDeferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProfileValueMapper())->apply($this->value(AttributeType::IMAGE), 'file-key');
    }

    #[DataProvider('datesContainingNullBytes')]
    public function testMalformedDateRaisesValidationException(AttributeType $type, mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProfileValueMapper())->apply($this->value($type), $input);
    }

    public static function datesContainingNullBytes(): iterable
    {
        yield 'date' => [AttributeType::DATE, "2025-01\0-01"];
        yield 'period start' => [AttributeType::PERIOD, ['start' => "2025-01\0-01", 'end' => '2025-12-31']];
        yield 'period end' => [AttributeType::PERIOD, ['start' => '2025-01-01', 'end' => "2025-12\0-31"]];
    }

    private function value(AttributeType $type): \App\Entity\ProfileAttributeValue
    {
        $definition = new AttributeDefinition(new AttributeCategory('Skills'), 'Value', $type);
        return Profile::createWithBuiltIns(new User('candidate@example.test'), [])->selectAttribute($definition);
    }
}
