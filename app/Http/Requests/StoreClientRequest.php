<?php

namespace App\Http\Requests;

use App\Models\Business\Client;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreClientRequest extends ClientRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Client::class) ?? false;
    }

    public function rules(): array
    {
        return $this->clientRules();
    }

    protected function prepareForValidation(): void
    {
        if ($this->expectsJson()) {
            $this->merge(['is_active' => true]);
        }

        parent::prepareForValidation();
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Zadané údaje nejsou platné.',
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
