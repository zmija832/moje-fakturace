<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Clients\ClientNormalizer;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\ClientType;
use App\Models\Business\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientService
{
    private const SORT_COLUMNS = ['display_name', 'city', 'created_at'];

    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly CompanySettingsService $companySettingsService,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = Client::query();
        $status = in_array($filters['status'] ?? null, ['active', 'inactive', 'archived', 'all_non_archived'], true)
            ? $filters['status']
            : 'all_non_archived';

        match ($status) {
            'active' => $query->whereNull('archived_at')->where('is_active', true),
            'inactive' => $query->whereNull('archived_at')->where('is_active', false),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };

        if (in_array($filters['type'] ?? null, array_column(ClientType::cases(), 'value'), true)) {
            $query->where('type', $filters['type']);
        }

        $search = trim(mb_substr((string) ($filters['search'] ?? ''), 0, 100));

        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $pattern = '%'.$escaped.'%';

            $query->where(function ($query) use ($pattern): void {
                foreach (['display_name', 'company_name', 'first_name', 'last_name', 'registration_number', 'email', 'city'] as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}($column, 'like', $pattern);
                }
            });
        }

        $sort = in_array($filters['sort'] ?? null, self::SORT_COLUMNS, true)
            ? $filters['sort']
            : 'display_name';
        $direction = ($filters['direction'] ?? null) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function newForForm(): Client
    {
        $settings = $this->companySettingsService->forForm();

        return new Client([
            'type' => ClientType::Company,
            'country_code' => 'CZ',
            'default_currency' => $settings->default_currency,
            'default_due_days' => $settings->default_due_days,
            'default_payment_method' => $settings->default_payment_method,
            'language' => $settings->document_locale,
            'is_active' => true,
        ]);
    }

    public function find(string $uuid): Client
    {
        return Client::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function findForEdit(string $uuid): Client
    {
        return Client::query()->where('uuid', $uuid)->whereNull('archived_at')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Client
    {
        $connection = $this->connectionName();
        $attributes = $this->prepareAttributes($attributes);

        return DB::connection($connection)->transaction(function () use ($attributes): Client {
            $client = new Client;
            $client->fill($attributes);
            $client->save();
            $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::Client, $client);
            $this->auditWriter->write(
                BusinessAuditEvent::ClientCreated,
                BusinessAuditableType::Client,
                $client->uuid,
                null,
                $snapshot,
                array_keys($snapshot),
            );

            return $client->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $uuid, array $attributes): Client
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $attributes): Client {
            $client = $this->lockedClient($uuid, editableOnly: true);
            $oldValues = $this->auditSanitizer->snapshot(BusinessAuditableType::Client, $client);
            $attributes = $this->prepareAttributes($attributes, $client);
            $client->fill($attributes);
            $changedFields = $this->auditSanitizer->changedFields(BusinessAuditableType::Client, $client);
            $client->save();

            if ($changedFields !== []) {
                $this->auditWriter->write(
                    BusinessAuditEvent::ClientUpdated,
                    BusinessAuditableType::Client,
                    $client->uuid,
                    $oldValues,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::Client, $client),
                    $changedFields,
                );
            }

            return $client->refresh();
        }, 3);
    }

    public function deactivate(string $uuid): Client
    {
        return $this->changeActiveState($uuid, false);
    }

    public function activate(string $uuid): Client
    {
        return $this->changeActiveState($uuid, true);
    }

    public function archive(string $uuid): Client
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid): Client {
            $client = $this->lockedClient($uuid, editableOnly: true);
            $wasActive = (bool) $client->is_active;
            $client->forceFill(['is_active' => false, 'archived_at' => now()])->save();
            $this->auditWriter->write(
                BusinessAuditEvent::ClientArchived,
                BusinessAuditableType::Client,
                $client->uuid,
                ['is_active' => $wasActive, 'is_archived' => false],
                ['is_active' => false, 'is_archived' => true],
                ['is_active', 'archived_at'],
            );

            return $client->refresh();
        }, 3);
    }

    private function changeActiveState(string $uuid, bool $active): Client
    {
        $connection = $this->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $active): Client {
            $client = $this->lockedClient($uuid);

            if ($client->isArchived()) {
                throw ValidationException::withMessages([
                    'client' => 'Archivovaného klienta nelze aktivovat ani deaktivovat.',
                ]);
            }

            $oldActive = (bool) $client->is_active;
            $client->is_active = $active;
            $client->save();

            if ($oldActive !== $active) {
                $this->auditWriter->write(
                    $active ? BusinessAuditEvent::ClientActivated : BusinessAuditEvent::ClientDeactivated,
                    BusinessAuditableType::Client,
                    $client->uuid,
                    ['is_active' => $oldActive],
                    ['is_active' => $active],
                    ['is_active'],
                );
            }

            return $client->refresh();
        }, 3);
    }

    private function lockedClient(string $uuid, bool $editableOnly = false): Client
    {
        return Client::query()
            ->where('uuid', $uuid)
            ->when($editableOnly, fn ($query) => $query->whereNull('archived_at'))
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepareAttributes(array $attributes, ?Client $existing = null): array
    {
        $displayNameWasProvided = array_key_exists('display_name', $attributes);
        $attributes = ClientNormalizer::normalize($attributes);

        if (($attributes['display_name'] ?? null) === null) {
            if (! $displayNameWasProvided && $existing !== null) {
                unset($attributes['display_name']);
            } else {
                $attributes['display_name'] = $this->generatedDisplayName($attributes, $existing);
            }
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    private function generatedDisplayName(array $attributes, ?Client $existing): string
    {
        $type = $attributes['type'] ?? $existing?->type?->value;

        if ($type === ClientType::Company->value) {
            return (string) ($attributes['company_name'] ?? $existing?->company_name);
        }

        return trim(implode(' ', array_filter([
            $attributes['first_name'] ?? $existing?->first_name,
            $attributes['last_name'] ?? $existing?->last_name,
        ])));
    }

    private function connectionName(): string
    {
        return $this->connectionResolver->resolve()->connectionName();
    }
}
