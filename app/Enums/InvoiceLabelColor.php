<?php

namespace App\Enums;

enum InvoiceLabelColor: string
{
    case Slate = 'slate';
    case Blue = 'blue';
    case Emerald = 'emerald';
    case Amber = 'amber';
    case Rose = 'rose';
    case Violet = 'violet';

    public function label(): string
    {
        return match ($this) {
            self::Slate => 'Šedá',
            self::Blue => 'Modrá',
            self::Emerald => 'Zelená',
            self::Amber => 'Oranžová',
            self::Rose => 'Červená',
            self::Violet => 'Fialová',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Slate => 'invoice-label--slate',
            self::Blue => 'invoice-label--blue',
            self::Emerald => 'invoice-label--emerald',
            self::Amber => 'invoice-label--amber',
            self::Rose => 'invoice-label--rose',
            self::Violet => 'invoice-label--violet',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $color): array => [$color->value => $color->label()])->all();
    }
}
