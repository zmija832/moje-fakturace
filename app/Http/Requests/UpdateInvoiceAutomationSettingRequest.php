<?php

namespace App\Http\Requests;

use App\Models\Business\InvoiceAutomationSetting;
use App\Services\Business\AutomationTemplateRenderer;
use App\Services\Business\InvoiceAutomationSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInvoiceAutomationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', InvoiceAutomationSetting::class);
    }

    public function rules(): array
    {
        $rules = ['reminders_enabled' => ['required', 'boolean'], 'reminder_mode' => ['required', Rule::in(['prepare', 'send'])], 'notify_admin_when_paid' => ['required', 'boolean'], 'notify_customer_when_paid' => ['required', 'boolean'], 'partial_subject' => ['required', 'string', 'max:255'], 'partial_body' => ['required', 'string', 'max:10000'], 'paid_subject' => ['required', 'string', 'max:255'], 'paid_body' => ['required', 'string', 'max:10000'], 'overpaid_subject' => ['required', 'string', 'max:255'], 'overpaid_body' => ['required', 'string', 'max:10000']];
        foreach ([1, 2, 3] as $i) {
            $rules["reminder_day_$i"] = ['nullable', 'integer', 'between:1,365'];
            $rules["reminder_subject_$i"] = ['required', 'string', 'max:255'];
            $rules["reminder_body_$i"] = ['required', 'string', 'max:10000'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            $renderer = app(AutomationTemplateRenderer::class);
            foreach ([1, 2, 3] as $i) {
                foreach (["reminder_subject_$i", "reminder_body_$i"] as $f) {
                    try {
                        $renderer->assertSafe((string) $this->input($f), AutomationTemplateRenderer::REMINDER_PLACEHOLDERS);
                    } catch (\Throwable) {
                        $v->errors()->add($f, 'Šablona obsahuje nepovolený výraz.');
                    }
                }
            }foreach (['partial_subject', 'partial_body', 'paid_subject', 'paid_body', 'overpaid_subject', 'overpaid_body'] as $f) {
                try {
                    $renderer->assertSafe((string) $this->input($f), AutomationTemplateRenderer::PAID_PLACEHOLDERS);
                } catch (\Throwable) {
                    $v->errors()->add($f, 'Šablona obsahuje nepovolený výraz.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $defaults = app(InvoiceAutomationSettingsService::class)->defaults();
        $this->merge([
            'reminders_enabled' => $this->boolean('reminders_enabled'),
            'notify_admin_when_paid' => $this->boolean('notify_admin_when_paid'),
            'notify_customer_when_paid' => $this->boolean('notify_customer_when_paid'),
            'partial_subject' => $this->input('partial_subject', $defaults['partial_subject']),
            'partial_body' => $this->input('partial_body', $defaults['partial_body']),
            'overpaid_subject' => $this->input('overpaid_subject', $defaults['overpaid_subject']),
            'overpaid_body' => $this->input('overpaid_body', $defaults['overpaid_body']),
        ]);
    }
}
