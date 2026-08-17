<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['sender_name', 'reply_to', 'subject_template', 'body_template', 'signature', 'attach_pdf', 'include_web_invoice'])]
class InvoiceEmailSetting extends BusinessModel
{
    public const SINGLETON_KEY = '1';

    protected function casts(): array
    {
        return [
            'attach_pdf' => 'boolean',
            'include_web_invoice' => 'boolean',
        ];
    }
}
