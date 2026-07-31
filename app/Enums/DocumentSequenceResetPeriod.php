<?php

namespace App\Enums;

enum DocumentSequenceResetPeriod: string
{
    case Never = 'never';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Nikdy',
            self::Yearly => 'Každý rok',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $period): array => [$period->value, $period->label()],
            self::cases(),
        ), 1, 0);
    }
}
