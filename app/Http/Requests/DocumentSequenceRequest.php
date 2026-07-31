<?php

namespace App\Http\Requests;

use App\Enums\DocumentSequenceResetPeriod;
use App\Enums\DocumentSequenceYearFormat;
use App\Enums\DocumentType;
use App\Http\Requests\Concerns\NormalizesBooleanInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class DocumentSequenceRequest extends FormRequest
{
    use NormalizesBooleanInput;

    /** @return array<string, list<mixed>> */
    protected function documentSequenceRules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:64'],
            'suffix' => ['nullable', 'string', 'max:64'],
            'year_format' => ['required', Rule::enum(DocumentSequenceYearFormat::class)],
            'sequence_digits' => ['required', 'integer', 'between:1,12'],
            'start_number' => ['required', 'integer', 'between:1,999999999999'],
            'reset_period' => ['required', Rule::enum(DocumentSequenceResetPeriod::class)],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $number = (string) $this->input('start_number');
            $digits = (int) $this->input('sequence_digits');

            if ($digits > 0 && ctype_digit($number) && strlen($number) > $digits) {
                $validator->errors()->add(
                    'start_number',
                    'Počáteční číslo se musí vejít do zvoleného počtu číslic.',
                );
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'document_type' => 'typ dokladu',
            'name' => 'název řady',
            'prefix' => 'prefix',
            'suffix' => 'suffix',
            'year_format' => 'formát roku',
            'sequence_digits' => 'počet číslic',
            'start_number' => 'počáteční číslo',
            'reset_period' => 'resetování',
            'is_active' => 'aktivní řada',
            'sort_order' => 'pořadí',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'prefix' => trim((string) $this->input('prefix')),
            'suffix' => trim((string) $this->input('suffix')),
            'name' => trim((string) $this->input('name')),
            'is_active' => $this->normalizedBooleanInput('is_active'),
        ]);
    }
}
