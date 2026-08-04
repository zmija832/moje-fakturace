<?php

namespace App\Enums;

enum InvoicePaymentSource: string
{
    case Manual = 'manual';
    case FutureBankImport = 'future_bank_import';
}
