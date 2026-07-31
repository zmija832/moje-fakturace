<?php

namespace App\Enums;

enum ClientType: string
{
    case Company = 'company';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Firma',
            self::Person => 'Fyzická osoba',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
