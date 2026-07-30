<?php

namespace App\Domain\CompanySettings;

final class CompanySettingOptions
{
    public const COUNTRIES = [
        'CZ' => 'Česko',
        'SK' => 'Slovensko',
    ];

    public const CURRENCIES = [
        'CZK' => 'CZK',
        'EUR' => 'EUR',
    ];

    public const DOCUMENT_LOCALES = [
        'cs' => 'Čeština',
        'sk' => 'Slovenština',
    ];

    public const TIMEZONES = [
        'Europe/Prague' => 'Europe/Prague',
        'Europe/Bratislava' => 'Europe/Bratislava',
    ];

    private function __construct() {}
}
