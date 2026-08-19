<?php

namespace App\Console\Commands;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Models\Business;
use App\Services\Business\BusinessDate;
use App\Services\Business\InvoicePaidNotificationService;
use App\Services\Business\InvoiceReminderService;
use App\Services\Business\RecurringInvoiceRunner;
use Illuminate\Console\Command;
use Throwable;

class RunInvoiceAutomationCommand extends Command
{
    protected $signature = 'app:run-invoice-automation {--business= : UUID konkrétního subjektu} {--limit=50 : Maximální počet položek na typ a tenant}';

    protected $description = 'Idempotentně zpracuje opakované faktury, upomínky a retry notifikací po zaplacení';

    public function handle(ActiveBusinessContext $context, BusinessDate $businessDate): int
    {
        $requestedBusiness = trim((string) ($this->option('business') ?? ''));
        $query = Business::query()->where('is_active', true);
        if ($requestedBusiness !== '') {
            $query->where('uuid', $requestedBusiness);
        }
        $businesses = $query->orderBy('sort_order')->get();
        if ($requestedBusiness !== '' && $businesses->isEmpty()) {
            $this->error('Požadovaný aktivní fakturační subjekt nebyl nalezen.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($businesses as $business) {
            $context->set($business);
            try {
                $today = $businessDate->today();
                $limit = max(1, min(100, (int) $this->option('limit')));
                $recurring = app(RecurringInvoiceRunner::class)->runDue($today, $limit);
                $reminders = app(InvoiceReminderService::class)->runDue($today, $limit);
                $paidNotifications = app(InvoicePaidNotificationService::class)->retryDue($limit);
                $this->line("{$business->display_name}: recurring {$recurring['processed']}/{$recurring['failed']}, reminders {$reminders['processed']}/{$reminders['failed']}, paid notifications {$paidNotifications['processed']}/{$paidNotifications['failed']}");
                $failed = $failed || $recurring['failed'] > 0 || $reminders['failed'] > 0 || $paidNotifications['failed'] > 0;
            } catch (Throwable $e) {
                $failed = true;
                report($e);
                $this->error("{$business->display_name}: automatizace selhala (".class_basename($e).').');
            } finally {
                $context->clear();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
