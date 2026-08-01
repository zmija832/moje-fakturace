<?php

namespace App\Enums;

enum VatTaxType: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
    case Zero = 'zero';
    case Exempt = 'exempt';
    case ReverseCharge = 'reverse_charge';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Základní sazba',
            self::Reduced => 'Snížená sazba',
            self::Zero => 'Nulová sazba',
            self::Exempt => 'Osvobozené plnění',
            self::ReverseCharge => 'Přenesená daňová povinnost',
            self::OutOfScope => 'Mimo předmět DPH',
        };
    }

    public function requiresPercentage(): bool
    {
        return in_array($this, [self::Standard, self::Reduced, self::Zero], true);
    }

    public function allowedAsNonPayerDefault(): bool
    {
        return in_array($this, [self::Exempt, self::OutOfScope], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $type): array => [$type->value, $type->label()],
            self::cases(),
        ), 1, 0);
    }
}
