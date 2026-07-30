<?php

namespace App\Enums;

enum DefaultPaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case CashOnDelivery = 'cod';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bankovní převod',
            self::Cash => 'Hotově',
            self::Card => 'Platební karta',
            self::CashOnDelivery => 'Dobírka',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $method) {
            $options[$method->value] = $method->label();
        }

        return $options;
    }
}
