<?php

declare(strict_types=1);

namespace App\Profile;

use App\Entity\ProfileAttributeValue;
use App\Enum\AttributeType;

final class ProfileValueMapper
{
    public function apply(ProfileAttributeValue $value, mixed $input): void
    {
        match ($value->getDefinition()->getType()) {
            AttributeType::STRING, AttributeType::TEXT => $this->text($value, $input),
            AttributeType::NUMERIC => $this->numeric($value, $input),
            AttributeType::DATE => $this->date($value, $input),
            AttributeType::PERIOD => $this->period($value, $input),
            AttributeType::BOOLEAN => $this->boolean($value, $input),
            AttributeType::SELECT => $this->option($value, $input),
            AttributeType::IMAGE => throw new \InvalidArgumentException('Image upload is not available yet.'),
        };
    }

    private function text(ProfileAttributeValue $value, mixed $input): void
    {
        $text = $this->string($input);
        if (trim($text) === '') {
            $value->clearValue();
        } else {
            $value->setText($text);
        }
    }

    private function numeric(ProfileAttributeValue $value, mixed $input): void
    {
        $decimal = trim($this->string($input));
        if ($decimal === '') {
            $value->clearValue();
        } else {
            $value->setNumeric($decimal);
        }
    }

    private function date(ProfileAttributeValue $value, mixed $input): void
    {
        $raw = trim($this->string($input));
        if ($raw === '') {
            $value->clearValue();
        } else {
            $value->setDate($this->parseDate($raw));
        }
    }

    private function period(ProfileAttributeValue $value, mixed $input): void
    {
        if (!is_array($input) || !array_key_exists('start', $input) || !array_key_exists('end', $input)) {
            throw new \InvalidArgumentException('A period needs start and end dates.');
        }

        $start = trim($this->string($input['start']));
        $end = trim($this->string($input['end']));

        if ($start === '' && $end === '') {
            $value->clearValue();
        } elseif ($start === '' || $end === '') {
            throw new \InvalidArgumentException('A period needs both dates.');
        } else {
            $value->setPeriod($this->parseDate($start), $this->parseDate($end));
        }
    }

    private function boolean(ProfileAttributeValue $value, mixed $input): void
    {
        $raw = $this->string($input);
        match ($raw) {
            '' => $value->clearValue(),
            'true' => $value->setBoolean(true),
            'false' => $value->setBoolean(false),
            default => throw new \InvalidArgumentException('Choose Not set, Yes, or No.'),
        };
    }

    private function option(ProfileAttributeValue $value, mixed $input): void
    {
        $raw = $this->string($input);
        if ($raw === '') {
            $value->clearValue();
            return;
        }

        if (!ctype_digit($raw)) {
            throw new \InvalidArgumentException('Choose a valid option.');
        }

        foreach ($value->getDefinition()->getOptions() as $option) {
            if ((string) $option->getId() === $raw) {
                $value->setOption($option);
                return;
            }
        }

        throw new \InvalidArgumentException('Choose an option for this attribute.');
    }

    private function string(mixed $input): string
    {
        if (!is_string($input)) {
            throw new \InvalidArgumentException('Invalid value representation.');
        }

        return $input;
    }

    private function parseDate(string $input): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($date === false || $date->format('Y-m-d') !== $input) {
            throw new \InvalidArgumentException('Enter a valid date.');
        }

        return $date;
    }
}
