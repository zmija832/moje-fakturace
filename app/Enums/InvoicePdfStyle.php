<?php

namespace App\Enums;

enum InvoicePdfStyle: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Elegant = 'elegant';
    case Compact = 'compact';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Klasická',
            self::Modern => 'Moderní',
            self::Elegant => 'Elegantní',
            self::Compact => 'Kompaktní',
            self::Corporate => 'Firemní',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Classic => 'Konzervativní česká faktura s minimem dekorací.',
            self::Modern => 'Výrazná hlavička a zvýrazněné platební údaje.',
            self::Elegant => 'Vzdušné rozložení s jemnými neutrálními barvami.',
            self::Compact => 'Úsporné rozložení vhodné pro delší seznam položek.',
            self::Corporate => 'Formální B2B vzhled s pevně strukturovanou hlavičkou.',
        };
    }

    /** @return array<string, string|int|bool> */
    public function theme(): array
    {
        return match ($this) {
            self::Classic => ['primary' => '#172033', 'muted' => '#5b6474', 'border' => '#dfe4ec', 'soft' => '#eef2f7', 'header' => '#ffffff', 'header_text' => '#172033', 'accent' => '#334155', 'page_margin' => 16, 'font_size' => 10, 'cell_padding' => 5, 'section_margin' => 17],
            self::Modern => ['primary' => '#0f172a', 'muted' => '#475569', 'border' => '#bfdbfe', 'soft' => '#eff6ff', 'header' => '#0f172a', 'header_text' => '#ffffff', 'accent' => '#2563eb', 'page_margin' => 14, 'font_size' => 10, 'cell_padding' => 6, 'section_margin' => 16],
            self::Elegant => ['primary' => '#292524', 'muted' => '#78716c', 'border' => '#e7e0d4', 'soft' => '#f8f5ef', 'header' => '#f5f1e8', 'header_text' => '#292524', 'accent' => '#7c5c36', 'page_margin' => 18, 'font_size' => 10, 'cell_padding' => 6, 'section_margin' => 20],
            self::Compact => ['primary' => '#111827', 'muted' => '#4b5563', 'border' => '#d1d5db', 'soft' => '#f8fafc', 'header' => '#ffffff', 'header_text' => '#111827', 'accent' => '#0f766e', 'page_margin' => 11, 'font_size' => 9, 'cell_padding' => 3, 'section_margin' => 11],
            self::Corporate => ['primary' => '#1e293b', 'muted' => '#475569', 'border' => '#cbd5e1', 'soft' => '#eaf0f7', 'header' => '#1e3a5f', 'header_text' => '#ffffff', 'accent' => '#1d4ed8', 'page_margin' => 14, 'font_size' => 10, 'cell_padding' => 5, 'section_margin' => 15],
        };
    }
}
