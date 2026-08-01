<?php

namespace App\Http\Requests;

use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessAuditIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AuditLog::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'event' => ['nullable', Rule::enum(BusinessAuditEvent::class)],
            'auditable_type' => ['nullable', Rule::enum(BusinessAuditableType::class)],
            'actor' => ['nullable', 'string', 'max:100'],
            'request_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', Rule::in(['occurred_at', 'event', 'auditable_type', 'actor_name'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'actor' => trim(mb_substr((string) $this->query('actor', ''), 0, 100)),
            'request_id' => trim(mb_substr((string) $this->query('request_id', ''), 0, 64)),
            'sort' => in_array($this->query('sort'), ['occurred_at', 'event', 'auditable_type', 'actor_name'], true)
                ? $this->query('sort')
                : 'occurred_at',
            'direction' => $this->query('direction') === 'asc' ? 'asc' : 'desc',
        ]);
    }
}
