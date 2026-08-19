<?php

namespace App\Enums;

enum InvoiceReminderOrigin: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
