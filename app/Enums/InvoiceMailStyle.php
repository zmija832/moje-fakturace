<?php

namespace App\Enums;

enum InvoiceMailStyle: string
{
    case Minimal = 'minimal';
    case Modern = 'modern';
    case Elegant = 'elegant';
    case Compact = 'compact';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Minimal => 'Minimalistická',
            self::Modern => 'Moderní',
            self::Elegant => 'Elegantní',
            self::Compact => 'Kompaktní',
            self::Corporate => 'Firemní',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Minimal => 'Vzdušný vzhled bez výrazných dekorací.',
            self::Modern => 'Čistá karta s výraznou hlavní akcí.',
            self::Elegant => 'Jemné neutrální barvy a decentní typografie.',
            self::Compact => 'Úsporné rozložení s menšími rozestupy.',
            self::Corporate => 'Formálnější hlavička a strukturovaný souhrn.',
        };
    }

    /** @return array<string, string|int> */
    public function theme(): array
    {
        return match ($this) {
            self::Minimal => ['page' => '#ffffff', 'panel' => '#ffffff', 'header' => '#ffffff', 'header_text' => '#0f172a', 'accent' => '#334155', 'border' => '#e2e8f0', 'radius' => 0, 'spacing' => 28],
            self::Modern => ['page' => '#f1f5f9', 'panel' => '#ffffff', 'header' => '#0f172a', 'header_text' => '#ffffff', 'accent' => '#2563eb', 'border' => '#dbeafe', 'radius' => 14, 'spacing' => 30],
            self::Elegant => ['page' => '#f8f7f4', 'panel' => '#ffffff', 'header' => '#f5f1e8', 'header_text' => '#292524', 'accent' => '#7c5c36', 'border' => '#e7e0d4', 'radius' => 8, 'spacing' => 32],
            self::Compact => ['page' => '#f8fafc', 'panel' => '#ffffff', 'header' => '#ffffff', 'header_text' => '#111827', 'accent' => '#0f766e', 'border' => '#d1d5db', 'radius' => 6, 'spacing' => 20],
            self::Corporate => ['page' => '#eef2f7', 'panel' => '#ffffff', 'header' => '#1e3a5f', 'header_text' => '#ffffff', 'accent' => '#1d4ed8', 'border' => '#cbd5e1', 'radius' => 4, 'spacing' => 28],
        };
    }
}
