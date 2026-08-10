<?php

namespace App\Http\Requests;

use App\Models\Business\Client;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AresClientLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Client::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'ico' => ['required', 'string', 'regex:/^\d{8}$/'],
            'business_id' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return ['ico' => 'IČO'];
    }

    protected function prepareForValidation(): void
    {
        $ico = $this->input('ico');

        if (is_string($ico)) {
            $this->merge(['ico' => preg_replace('/\s+/u', '', trim($ico))]);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Zadané IČO není platné.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
