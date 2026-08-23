<?php

namespace App\Http\Requests;

use App\Enums\InvoiceMailStyle;
use App\Http\Requests\Concerns\NormalizesBooleanInput;
use App\Models\Business\InvoiceEmailSetting;
use App\Services\Business\InvoiceEmailTemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInvoiceEmailSettingRequest extends FormRequest
{
    use NormalizesBooleanInput;

    public function authorize(): bool
    {
        return Gate::allows('updateAny', InvoiceEmailSetting::class);
    }

    public function rules(): array
    {
        return [
            'sender_name' => ['required', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'template_style' => ['required', Rule::enum(InvoiceMailStyle::class)],
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2400,max_height=1200'],
            'remove_logo' => ['required', 'boolean'],
            'subject_template' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string', 'max:10000'],
            'signature' => ['nullable', 'string', 'max:5000'],
            'signature_name' => ['nullable', 'string', 'max:255'],
            'signature_role' => ['nullable', 'string', 'max:255'],
            'signature_phone' => ['nullable', 'string', 'max:64'],
            'signature_email' => ['nullable', 'email', 'max:255'],
            'signature_web' => ['nullable', 'url:http,https', 'max:500'],
            'signature_text' => ['nullable', 'string', 'max:2000'],
            'attach_pdf' => ['required', 'boolean'],
            'include_web_invoice' => ['required', 'boolean'],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['subject_template', 'body_template', 'signature'] as $field) {
                $value = $this->input($field);
                if (! is_string($value)) {
                    continue;
                }
                preg_match_all('/\{[a-z_]+\}/', $value, $matches);
                $unknown = array_diff(array_unique($matches[0]), InvoiceEmailTemplateRenderer::PLACEHOLDERS);
                if ($unknown !== []) {
                    $validator->errors()->add($field, 'Šablona obsahuje nepodporovaný placeholder: '.implode(', ', $unknown).'.');
                }
                $remainder = str_replace(InvoiceEmailTemplateRenderer::PLACEHOLDERS, '', $value);
                if (str_contains($remainder, '{') || str_contains($remainder, '}') || str_contains($remainder, '<?') || preg_match('/@php\\b/i', $remainder) === 1) {
                    $validator->errors()->add($field, 'Šablona nesmí obsahovat PHP, Blade ani jiné výrazy.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'template_style' => $this->input('template_style', InvoiceMailStyle::Modern->value),
            'attach_pdf' => $this->normalizedBooleanInput('attach_pdf'),
            'include_web_invoice' => $this->normalizedBooleanInput('include_web_invoice'),
            'remove_logo' => $this->normalizedBooleanInput('remove_logo'),
        ]);
    }
}
