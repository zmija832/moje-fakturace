<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditRequestContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

class BusinessAuditWriter
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditRequestContext $requestContext,
    ) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>|null  $metadata
     */
    public function write(
        BusinessAuditEvent $event,
        BusinessAuditableType $auditableType,
        ?string $auditableUuid,
        ?array $oldValues,
        ?array $newValues,
        array $changedFields,
        ?BusinessAuditableType $subjectType = null,
        ?string $subjectUuid = null,
        ?array $metadata = null,
    ): AuditLog {
        $connection = $this->connectionResolver->resolve()->connectionName();

        if (DB::connection($connection)->transactionLevel() < 1) {
            throw new LogicException('Business audit musí vzniknout uvnitř doménové transakce.');
        }

        $actor = auth()->user();
        $actor = $actor instanceof User ? $actor : null;
        $request = $this->requestContext->requestId() !== null && app()->bound('request')
            ? app('request')
            : null;
        $request = $request instanceof Request ? $request : null;

        $audit = new AuditLog;
        $audit->forceFill([
            'event' => $event->value,
            'actor_user_uuid' => $actor ? 'central-user:'.$actor->id : null,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'auditable_type' => $auditableType->value,
            'auditable_uuid' => $auditableUuid,
            'subject_type' => $subjectType?->value,
            'subject_uuid' => $subjectUuid,
            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'changed_fields' => $changedFields === [] ? null : array_values(array_unique($changedFields)),
            'metadata' => $metadata === [] ? null : $metadata,
            'request_id' => $this->requestContext->requestId(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'occurred_at' => now(),
        ]);
        $audit->save();

        return $audit;
    }
}
