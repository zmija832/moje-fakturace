<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['sender_name', 'reply_to', 'template_style', 'pdf_style', 'logo_path', 'subject_template', 'body_template', 'signature', 'signature_name', 'signature_role', 'signature_phone', 'signature_email', 'signature_web', 'signature_text', 'attach_pdf', 'include_web_invoice'])]
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
