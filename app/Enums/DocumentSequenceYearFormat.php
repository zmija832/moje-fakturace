<?php

namespace App\Enums;

enum DocumentSequenceYearFormat: string
{
    case None = 'none';
    case TwoDigits = 'yy';
    case FourDigits = 'yyyy';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Bez roku',
            self::TwoDigits => 'Dvě číslice (YY)',
            self::FourDigits => 'Čtyři číslice (YYYY)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $format): array => [$format->value, $format->label()],
            self::cases(),
        ), 1, 0);
    }
}
