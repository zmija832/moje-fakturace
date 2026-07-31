<?php

namespace App\Http\Requests;

use App\Enums\ClientType;
use App\Models\Business\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Client::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'archived', 'all_non_archived'])],
            'type' => ['nullable', Rule::in(['all', ...array_column(ClientType::cases(), 'value')])],
            'sort' => ['nullable', Rule::in(['display_name', 'city', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => trim(mb_substr((string) $this->query('search', ''), 0, 100)),
            'status' => in_array($this->query('status'), ['active', 'inactive', 'archived', 'all_non_archived'], true)
                ? $this->query('status')
                : 'all_non_archived',
            'type' => in_array($this->query('type'), ['all', ...array_column(ClientType::cases(), 'value')], true)
                ? $this->query('type')
                : 'all',
            'sort' => in_array($this->query('sort'), ['display_name', 'city', 'created_at'], true)
                ? $this->query('sort')
                : 'display_name',
            'direction' => $this->query('direction') === 'desc' ? 'desc' : 'asc',
        ]);
    }
}
