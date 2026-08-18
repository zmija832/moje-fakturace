<?php

namespace App\Enums;

enum InvoiceDisplayState: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Koncept',
            self::Unpaid => 'Neuhrazená',
            self::PartiallyPaid => 'Částečně uhrazená',
            self::Paid => 'Uhrazená',
            self::Overpaid => 'Přeplacená',
            self::Overdue => 'Po splatnosti',
            self::Cancelled => 'Stornovaná',
            self::Archived => 'Archivovaná',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Unpaid => 'bg-amber-100 text-amber-900 ring-amber-200',
            self::PartiallyPaid => 'bg-blue-100 text-blue-800 ring-blue-200',
            self::Paid => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            self::Overpaid => 'bg-violet-100 text-violet-800 ring-violet-200',
            self::Overdue => 'bg-red-100 text-red-800 ring-red-200',
            self::Cancelled => 'bg-slate-200 text-slate-800 ring-slate-300',
            self::Archived => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }

    public function rowClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-50/70 border-l-4 border-l-slate-400',
            self::Unpaid => 'bg-amber-50/60 border-l-4 border-l-amber-400',
            self::PartiallyPaid => 'bg-blue-50/60 border-l-4 border-l-blue-500',
            self::Paid => 'bg-emerald-50/60 border-l-4 border-l-emerald-500',
            self::Overpaid => 'bg-violet-50/60 border-l-4 border-l-violet-500',
            self::Overdue => 'bg-red-50/60 border-l-4 border-l-red-500',
            self::Cancelled => 'bg-slate-100/80 border-l-4 border-l-rose-400 text-slate-600',
            self::Archived => 'bg-slate-50/80 border-l-4 border-l-slate-300 text-slate-600',
        };
    }
}
