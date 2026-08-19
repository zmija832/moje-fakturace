<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\InvoiceEmailDeliveryStatus;
use App\Models\Business\CompanySetting;
use App\Models\Business\Invoice;
use App\Models\Business\RecurringInvoiceRun;
use App\Models\Business\RecurringInvoiceTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RecurringInvoiceRunner
{
    public function __construct(private readonly BusinessConnectionResolver $resolver, private readonly InvoiceDraftService $drafts, private readonly InvoiceIssuer $issuer, private readonly InvoicePdfGenerator $pdf, private readonly InvoiceMailer $mailer) {}

    public function run(RecurringInvoiceTemplate $template, ?CarbonImmutable $scheduledOn = null): RecurringInvoiceRun
    {
        $connection = $this->resolver->resolve()->connectionName();
        $scheduledOn ??= $template->next_run_on->toImmutable();
        $run = $this->reserve($template, $scheduledOn, $connection);
        if (in_array($run->status, ['draft_created', 'issued', 'sent'], true) || ! $this->claim($run, $connection)) {
            return $run->refresh();
        }
        try {
            $invoice = $this->invoiceForRun($run, $template, $scheduledOn, $connection);
            $status = 'draft_created';
            if ($template->mode === 'auto_issue') {
                $invoice = $this->issuer->issue($invoice->uuid, $invoice->version, $run->correlation_uuid);
                $this->pdf->generate($invoice->uuid, $run->correlation_uuid);
                $status = 'issued';
                if ($template->auto_send) {
                    $delivery = $this->mailer->send($invoice->uuid, $run->correlation_uuid, []);
                    if ($delivery->status !== InvoiceEmailDeliveryStatus::Sent) {
                        throw new \RuntimeException('Automatické odeslání faktury nebylo potvrzeno jako úspěšné.');
                    }
                    $status = 'sent';
                }
            }
            DB::connection($connection)->transaction(function () use ($template, $run, $scheduledOn, $status): void {
                $locked = RecurringInvoiceTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
                if ($locked->next_run_on->isSameDay($scheduledOn)) {
                    $locked->forceFill(['next_run_on' => $this->nextDate($scheduledOn, $locked->interval_months, $locked->anchor_day), 'last_run_at' => now()])->save();
                }
                RecurringInvoiceRun::query()->whereKey($run->id)->update(['status' => $status, 'finished_at' => now(), 'updated_at' => now()]);
            }, 3);

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'failure_code' => class_basename($exception),
                'failure_message' => 'Automatizace faktury selhala. Podrobnost je v aplikačním logu.',
            ])->save();
            throw $exception;
        }
    }

    public function runDue(CarbonImmutable $today, int $limit = 50): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        RecurringInvoiceTemplate::query()->where('is_active', true)->whereDate('next_run_on', '<=', $today)->orderBy('next_run_on')->limit($limit)->get()->each(function ($template) use (&$result): void {
            try {
                $this->run($template);
                $result['processed']++;
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        });

        return $result;
    }

    private function reserve(RecurringInvoiceTemplate $template, CarbonImmutable $date, string $connection): RecurringInvoiceRun
    {
        $existing = RecurringInvoiceRun::query()->where('recurring_invoice_template_id', $template->id)->whereDate('scheduled_on', $date)->first();
        if ($existing) {
            return $existing;
        }
        try {
            return DB::connection($connection)->transaction(function () use ($template, $date): RecurringInvoiceRun {
                $run = new RecurringInvoiceRun;
                $run->forceFill(['recurring_invoice_template_id' => $template->id, 'scheduled_on' => $date, 'status' => 'pending', 'correlation_uuid' => (string) Str::uuid(), 'started_at' => now()])->save();

                return $run;
            }, 3);
        } catch (QueryException) {
            return RecurringInvoiceRun::query()->where('recurring_invoice_template_id', $template->id)->whereDate('scheduled_on', $date)->firstOrFail();
        }
    }

    private function claim(RecurringInvoiceRun $run, string $connection): bool
    {
        return DB::connection($connection)->transaction(function () use ($run): bool {
            $locked = RecurringInvoiceRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['draft_created', 'issued', 'sent'], true)) {
                return false;
            }
            if ($locked->status === 'running' && $locked->started_at->greaterThan(now()->subMinutes(15))) {
                return false;
            }
            $locked->forceFill(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'failure_code' => null, 'failure_message' => null])->save();

            return true;
        }, 3);
    }

    private function createDraft(RecurringInvoiceTemplate $template, CarbonImmutable $date): Invoice
    {
        $template->loadMissing('items');
        $payer = (bool) CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->value('is_vat_payer');
        $items = $template->items->map(function ($item) use ($payer): array {
            $data = ['position' => $item->position, 'description' => $item->description, 'quantity' => $this->trimDecimal($item->quantity), 'unit' => $item->unit, 'unit_price' => $this->trimDecimal($item->unit_price), 'discount_type' => $item->discount_type, 'discount_value' => $item->discount_value === null ? null : $this->trimDecimal($item->discount_value)];
            if ($payer) {
                $data['vat_rate_uuid'] = $item->vat_rate_uuid;
            }

            return $data;
        })->all();

        return $this->drafts->create(['customer_uuid' => $template->client_uuid, 'bank_account_uuid' => $template->bank_account_uuid, 'currency' => $template->currency, 'issued_on' => $date->format('Y-m-d'), 'taxable_supply_on' => $date->format('Y-m-d'), 'due_on' => $date->addDays($template->due_days)->format('Y-m-d'), 'payment_method' => $template->payment_method, 'note' => $template->note, 'invoice_discount_type' => $template->invoice_discount_type, 'invoice_discount_value' => $template->invoice_discount_value, 'items' => $items]);
    }

    private function invoiceForRun(RecurringInvoiceRun $run, RecurringInvoiceTemplate $template, CarbonImmutable $date, string $connection): Invoice
    {
        return DB::connection($connection)->transaction(function () use ($run, $template, $date): Invoice {
            $lockedRun = RecurringInvoiceRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->invoice_uuid !== null) {
                return Invoice::query()->where('uuid', $lockedRun->invoice_uuid)->firstOrFail();
            }

            $invoice = $this->createDraft($template, $date);
            $lockedRun->forceFill(['invoice_uuid' => $invoice->uuid])->save();
            $run->forceFill(['invoice_uuid' => $invoice->uuid]);

            return $invoice;
        }, 3);
    }

    private function nextDate(CarbonImmutable $date, int $months, int $anchor): CarbonImmutable
    {
        $target = $date->startOfMonth()->addMonthsNoOverflow($months);

        return $target->day(min($anchor, $target->daysInMonth));
    }

    private function trimDecimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' ? '0' : $value;
    }
}
