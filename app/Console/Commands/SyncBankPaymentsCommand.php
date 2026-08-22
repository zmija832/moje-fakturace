<?php

namespace App\Console\Commands;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Models\Business;
use App\Services\Business\BusinessDate;
use App\Services\Business\FioBankSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncBankPaymentsCommand extends Command
{
    protected $signature = 'app:sync-bank-payments {--business= : UUID konkrétního subjektu}';

    protected $description = 'Bezpečně synchronizuje příchozí Fio platby aktivních fakturačních subjektů';

    public function handle(ActiveBusinessContext $context): int
    {
        $requested = trim((string) ($this->option('business') ?? ''));
        $query = Business::query()->where('is_active', true);
        if ($requested !== '') {
            $query->where('uuid', $requested);
        }
        $businesses = $query->orderBy('sort_order')->get();
        if ($requested !== '' && $businesses->isEmpty()) {
            $this->error('Požadovaný aktivní fakturační subjekt nebyl nalezen.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($businesses as $business) {
            $context->set($business);
            try {
                $result = app(FioBankSyncService::class)->syncAll(app(BusinessDate::class)->today());
                foreach ($result['accounts'] as $account) {
                    $this->line("{$business->display_name} / {$account['bank_account_uuid']}: fetched {$account['fetched']}, new {$account['new']}, matched {$account['matched']}, unmatched {$account['unmatched']}, duplicates {$account['duplicates']}");
                }
                $failed = $failed || $result['failed'] > 0;
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("{$business->display_name}: synchronizace bankovních plateb selhala (".class_basename($exception).').');
            } finally {
                $context->clear();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
