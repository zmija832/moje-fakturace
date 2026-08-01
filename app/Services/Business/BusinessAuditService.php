<?php

namespace App\Services\Business;

use App\Enums\BusinessAuditableType;
use App\Models\Business\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class BusinessAuditService
{
    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, AuditLog> */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query();

        if (is_string($filters['date_from'] ?? null)) {
            $query->where('occurred_at', '>=', $filters['date_from'].' 00:00:00');
        }

        if (is_string($filters['date_to'] ?? null)) {
            $query->where('occurred_at', '<=', $filters['date_to'].' 23:59:59.999999');
        }

        foreach (['event', 'auditable_type', 'request_id'] as $field) {
            if (is_string($filters[$field] ?? null) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        $actor = trim((string) ($filters['actor'] ?? ''));

        if ($actor !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $actor);
            $pattern = '%'.$escaped.'%';
            $query->where(function ($query) use ($pattern): void {
                $query->where('actor_name', 'like', $pattern)
                    ->orWhere('actor_email', 'like', $pattern)
                    ->orWhere('actor_user_uuid', 'like', $pattern);
            });
        }

        $sort = in_array($filters['sort'] ?? null, ['occurred_at', 'event', 'auditable_type', 'actor_name'], true)
            ? $filters['sort']
            : 'occurred_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate(25)
            ->withQueryString();
    }

    public function find(string $uuid): AuditLog
    {
        return AuditLog::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** @return Collection<int, AuditLog> */
    public function forEntity(BusinessAuditableType $type, string $uuid, int $limit = 20): Collection
    {
        return AuditLog::query()
            ->where(function ($query) use ($type, $uuid): void {
                $query->where(function ($query) use ($type, $uuid): void {
                    $query->where('auditable_type', $type->value)->where('auditable_uuid', $uuid);
                })->orWhere(function ($query) use ($type, $uuid): void {
                    $query->where('subject_type', $type->value)->where('subject_uuid', $uuid);
                });
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }
}
