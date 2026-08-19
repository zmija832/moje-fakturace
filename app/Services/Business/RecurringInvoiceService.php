<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\RecurringInvoiceRun;
use App\Models\Business\RecurringInvoiceTemplate;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceService
{
    public function __construct(private readonly BusinessConnectionResolver $resolver, private readonly BusinessAuditSanitizer $sanitizer, private readonly BusinessAuditWriter $audit) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): RecurringInvoiceTemplate
    {
        return $this->persist(new RecurringInvoiceTemplate, $data);
    }

    /** @param array<string,mixed> $data */
    public function update(RecurringInvoiceTemplate $template, array $data): RecurringInvoiceTemplate
    {
        return $this->persist($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function persist(RecurringInvoiceTemplate $template, array $data): RecurringInvoiceTemplate
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($template, $data): RecurringInvoiceTemplate {
            $items = $data['items'];
            unset($data['items']);
            $created = ! $template->exists;
            $before = $created ? null : $this->sanitizer->snapshot(BusinessAuditableType::RecurringInvoice, $template);
            $data['anchor_day'] = (int) substr((string) $data['next_run_on'], 8, 2);
            if ($template->exists) {
                $template = RecurringInvoiceTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
                $data['version'] = $template->version + 1;
            }
            $template->fill($data);
            $template->save();
            $template->items()->delete();
            foreach (array_values($items) as $index => $item) {
                $template->items()->create([...$item, 'position' => $index + 1]);
            }
            $after = $this->sanitizer->snapshot(BusinessAuditableType::RecurringInvoice, $template);
            $this->audit->write($created ? BusinessAuditEvent::RecurringInvoiceCreated : BusinessAuditEvent::RecurringInvoiceUpdated, BusinessAuditableType::RecurringInvoice, $template->uuid, $before, $after, array_keys($after));

            return $template->refresh()->load('items');
        }, 3);
    }

    public function setActive(RecurringInvoiceTemplate $template, bool $active): RecurringInvoiceTemplate
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($template, $active): RecurringInvoiceTemplate {
            $locked = RecurringInvoiceTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $before = $this->sanitizer->snapshot(BusinessAuditableType::RecurringInvoice, $locked);
            $locked->forceFill(['is_active' => $active, 'version' => $locked->version + 1])->save();
            $after = $this->sanitizer->snapshot(BusinessAuditableType::RecurringInvoice, $locked);
            $this->audit->write($active ? BusinessAuditEvent::RecurringInvoiceResumed : BusinessAuditEvent::RecurringInvoicePaused, BusinessAuditableType::RecurringInvoice, $locked->uuid, $before, $after, ['is_active']);

            return $locked;
        }, 3);
    }

    public function auditManualRun(RecurringInvoiceTemplate $template, RecurringInvoiceRun $run): void
    {
        $connection = $this->resolver->resolve()->connectionName();
        DB::connection($connection)->transaction(function () use ($template, $run): void {
            $this->audit->write(
                BusinessAuditEvent::RecurringInvoiceManualRun,
                BusinessAuditableType::RecurringInvoice,
                $template->uuid,
                null,
                ['run_uuid' => $run->uuid, 'status' => $run->status],
                ['manual_run'],
            );
        }, 3);
    }
}
