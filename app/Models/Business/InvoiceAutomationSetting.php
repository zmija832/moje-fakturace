<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['reminders_enabled', 'reminder_mode', 'reminder_day_1', 'reminder_day_2', 'reminder_day_3', 'reminder_subject_1', 'reminder_body_1', 'reminder_subject_2', 'reminder_body_2', 'reminder_subject_3', 'reminder_body_3', 'notify_admin_when_paid', 'notify_customer_when_paid', 'paid_subject', 'paid_body'])]
class InvoiceAutomationSetting extends BusinessModel
{
    public const SINGLETON_KEY = '1';

    protected $primaryKey = 'singleton_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['reminders_enabled' => 'boolean', 'notify_admin_when_paid' => 'boolean', 'notify_customer_when_paid' => 'boolean', 'reminder_day_1' => 'integer', 'reminder_day_2' => 'integer', 'reminder_day_3' => 'integer'];
    }
}
