<?php

namespace App\Http\Requests;

use App\Domain\Vat\VatPercentage;
use App\Enums\VatTaxType;
use App\Http\Requests\Concerns\NormalizesBooleanInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class VatRateRequest extends FormRequest
{
    use NormalizesBooleanInput;

    /** @return array<string, list<mixed>> */
    protected function vatRateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'tax_type' => ['required', Rule::enum(VatTaxType::class)],
            'percentage' => ['nullable', 'string', 'max:16'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'connection' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'context' => ['prohibited'],
            'vat_rate_id' => ['prohibited'],
            'business_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = VatTaxType::tryFrom((string) $this->input('tax_type'));
            $rawPercentage = $this->input('percentage');
            $hasPercentage = is_string($rawPercentage) && $rawPercentage !== '';

            if ($type === null) {
                return;
            }

            if (! $type->requiresPercentage()) {
                if ($hasPercentage) {
                    $validator->errors()->add('percentage', 'Tento daňový režim nemá procentní sazbu.');
                }

                return;
            }

            if (! $hasPercentage) {
                $validator->errors()->add('percentage', 'Pro tento daňový režim je sazba povinná.');

                return;
            }

            try {
                $percentage = VatPercentage::from($rawPercentage);
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('percentage', $exception->getMessage());

                return;
            }

            if ($type === VatTaxType::Zero && ! $percentage->isZero()) {
                $validator->errors()->add('percentage', 'Nulová sazba musí být přesně 0 %.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'název',
            'code' => 'kód',
            'tax_type' => 'daňový režim',
            'percentage' => 'sazba v %',
            'valid_from' => 'platnost od',
            'valid_to' => 'platnost do',
            'is_active' => 'aktivní sazba',
            'sort_order' => 'pořadí',
        ];
    }

    protected function prepareForValidation(): void
    {
        $percentage = $this->input('percentage');
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'percentage' => is_scalar($percentage)
                ? str_replace(',', '.', trim((string) $percentage))
                : $percentage,
            'valid_to' => $this->filled('valid_to') ? trim((string) $this->input('valid_to')) : null,
            'is_active' => $this->normalizedBooleanInput('is_active'),
        ]);
    }
}
