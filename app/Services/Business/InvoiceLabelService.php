<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceLabel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceLabelService
{
    public function __construct(
        private readonly BusinessConnectionResolver $resolver,
        private readonly BusinessAuditWriter $audit,
    ) {}

    /** @return Collection<int, InvoiceLabel> */
    public function all(): Collection
    {
        $this->resolver->resolve();

        return InvoiceLabel::query()->withCount('invoices')->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, InvoiceLabel> */
    public function active(): Collection
    {
        $this->resolver->resolve();

        return InvoiceLabel::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get();
    }

    public function create(array $input): InvoiceLabel
    {
        return $this->save(null, $input);
    }

    public function update(string $uuid, array $input): InvoiceLabel
    {
        return $this->save($uuid, $input);
    }

    public function attach(string $invoiceUuid, string $labelUuid): void
    {
        $connection = $this->resolver->resolve()->connectionName();
        DB::connection($connection)->transaction(function () use ($invoiceUuid, $labelUuid): void {
            $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
            $label = InvoiceLabel::query()->where('uuid', $labelUuid)->lockForUpdate()->firstOrFail();
            if (! $label->is_active) {
                throw ValidationException::withMessages(['label_uuid' => 'Vybraný štítek není dostupný.']);
            }
            if ($invoice->labels()->whereKey($label->id)->exists()) {
                return;
            }
            $invoice->labels()->attach($label->id);
            $this->audit->write(
                BusinessAuditEvent::InvoiceLabelAttached,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                null,
                null,
                ['labels'],
                BusinessAuditableType::InvoiceLabel,
                $label->uuid,
                ['label_name' => $label->name, 'label_color' => $label->color->value],
            );
        }, 3);
    }

    public function detach(string $invoiceUuid, string $labelUuid): void
    {
        $connection = $this->resolver->resolve()->connectionName();
        DB::connection($connection)->transaction(function () use ($invoiceUuid, $labelUuid): void {
            $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
            $label = InvoiceLabel::query()->where('uuid', $labelUuid)->lockForUpdate()->firstOrFail();
            if ($invoice->labels()->detach($label->id) === 0) {
                return;
            }
            $this->audit->write(
                BusinessAuditEvent::InvoiceLabelDetached,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                null,
                null,
                ['labels'],
                BusinessAuditableType::InvoiceLabel,
                $label->uuid,
                ['label_name' => $label->name, 'label_color' => $label->color->value],
            );
        }, 3);
    }

    private function save(?string $uuid, array $input): InvoiceLabel
    {
        $connection = $this->resolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($uuid, $input): InvoiceLabel {
            $label = $uuid === null
                ? new InvoiceLabel
                : InvoiceLabel::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $before = $label->exists ? $this->snapshot($label) : null;
            $label->fill($input)->save();
            $after = $this->snapshot($label);
            $changed = $before === null
                ? array_keys($after)
                : array_keys(array_diff_assoc($after, $before));
            if ($changed !== []) {
                $this->audit->write(
                    $before === null ? BusinessAuditEvent::InvoiceLabelCreated : BusinessAuditEvent::InvoiceLabelUpdated,
                    BusinessAuditableType::InvoiceLabel,
                    $label->uuid,
                    $before,
                    $after,
                    $changed,
                );
            }

            return $label->refresh();
        }, 3);
    }

    /** @return array{name: string, color: string, is_active: bool} */
    private function snapshot(InvoiceLabel $label): array
    {
        return ['name' => $label->name, 'color' => $label->color->value, 'is_active' => (bool) $label->is_active];
    }
}
