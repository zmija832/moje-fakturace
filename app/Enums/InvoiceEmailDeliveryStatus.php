<?php

namespace App\Enums;

enum InvoiceEmailDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Čeká na odeslání',
            self::Sent => 'Odesláno',
            self::Failed => 'Odeslání selhalo',
        };
    }
}
