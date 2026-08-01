<?php

namespace App\Models\Business;

use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Concerns\HasServerGeneratedUuid;
use LogicException;

class AuditLog extends BusinessModel
{
    use HasServerGeneratedUuid;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Auditní záznam je neměnný.'));
        static::deleting(fn (): never => throw new LogicException('Auditní záznam nelze smazat.'));
    }

    protected function casts(): array
    {
        return [
            'event' => BusinessAuditEvent::class,
            'auditable_type' => BusinessAuditableType::class,
            'subject_type' => BusinessAuditableType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
