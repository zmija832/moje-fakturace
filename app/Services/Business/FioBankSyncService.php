<?php

namespace App\Services\Business;

use App\Domain\Banking\FioApiException;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Models\Business\BankAccount;
use App\Models\Business\BankTransaction;
use App\Models\Business\FioBankAccountSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class FioBankSyncService
{
    private const CLAIM_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly FioApiClient $client,
        private readonly BankTransactionMatcher $matcher,
    ) {}

    /** @return array{accounts: list<array<string, int|string>>, failed: int} */
    public function syncAll(CarbonImmutable $today): array
    {
        $settings = FioBankAccountSetting::query()
            ->with('bankAccount')
            ->where('is_enabled', true)
            ->whereHas('bankAccount', fn ($query) => $query->where('is_active', true)->whereNull('archived_at'))
            ->orderBy('id')
            ->get();
        $accounts = [];
        $failed = 0;
        foreach ($settings as $setting) {
            try {
                $accounts[] = $this->sync($setting, $today);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $accounts[] = ['bank_account_uuid' => $setting->bankAccount->uuid, 'fetched' => 0, 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'invalid' => 0, 'failed' => 1];
            }
        }

        return ['accounts' => $accounts, 'failed' => $failed];
    }

    /** @return array<string, int|string> */
    public function sync(FioBankAccountSetting $setting, CarbonImmutable $today): array
    {
        $claim = $this->claim($setting->id);
        if ($claim === null) {
            return ['bank_account_uuid' => $setting->bankAccount->uuid, 'fetched' => 0, 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'invalid' => 0, 'failed' => 0];
        }

        try {
            $setting = FioBankAccountSetting::query()->with('bankAccount')->findOrFail($setting->id);
            $statement = $this->client->transactions(
                (string) $setting->encrypted_token,
                BusinessDate::addDays($today, -2),
                BusinessDate::normalize($today),
            );
            $this->assertAccount($setting->bankAccount, $statement['account']);

            $stats = ['bank_account_uuid' => $setting->bankAccount->uuid, 'fetched' => count($statement['transactions']), 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'invalid' => $statement['invalid_count'], 'failed' => 0];
            foreach ($statement['transactions'] as $row) {
                try {
                    [$transaction, $created] = $this->import($setting->bankAccount, $row);
                    if (! $created) {
                        $stats['duplicates']++;
                    } else {
                        $stats['new']++;
                    }

                    if ($transaction->status !== 'unmatched') {
                        continue;
                    }

                    if ($this->matcher->matchAutomatically($transaction->load('bankAccount'))) {
                        $stats['matched']++;
                    } else {
                        $stats['unmatched']++;
                    }
                } catch (Throwable $exception) {
                    $stats['failed']++;
                    report($exception);
                }
            }
            $this->finishClaim($setting->id, $claim, null);

            return $stats;
        } catch (Throwable $exception) {
            $code = $exception instanceof FioApiException ? $exception->safeCode : 'sync_failed';
            $this->finishClaim($setting->id, $claim, $code);
            throw $exception;
        }
    }

    /** @param array<string, ?string> $row @return array{BankTransaction, bool} */
    private function import(BankAccount $account, array $row): array
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        try {
            return DB::connection($connection)->transaction(function () use ($account, $row): array {
                $existing = BankTransaction::query()
                    ->where('bank_account_id', $account->id)
                    ->where('source', 'fio')
                    ->where('external_transaction_id', $row['external_transaction_id'])
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    return [$existing, false];
                }
                $transaction = new BankTransaction;
                $transaction->forceFill([
                    ...$row,
                    'bank_account_id' => $account->id,
                    'source' => 'fio',
                    'status' => 'unmatched',
                    'imported_at' => now(),
                ])->save();

                return [$transaction->load('bankAccount'), true];
            }, 3);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $existing = BankTransaction::query()->where('bank_account_id', $account->id)->where('source', 'fio')->where('external_transaction_id', $row['external_transaction_id'])->first();
            if ($existing === null) {
                throw $exception;
            }

            return [$existing, false];
        }
    }

    private function claim(int $settingId): ?string
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($settingId): ?string {
            $setting = FioBankAccountSetting::query()
                ->whereHas('bankAccount', fn ($query) => $query->where('is_active', true)->whereNull('archived_at'))
                ->lockForUpdate()
                ->find($settingId);
            if ($setting === null) {
                return null;
            }
            if (! $setting->is_enabled || blank($setting->encrypted_token)) {
                return null;
            }
            if ($setting->sync_claimed_at !== null && $setting->sync_claimed_at->gt(now()->subSeconds(self::CLAIM_TIMEOUT_SECONDS))) {
                return null;
            }
            $token = (string) Str::uuid();
            $setting->forceFill(['sync_claim_token' => $token, 'sync_claimed_at' => now(), 'last_attempt_at' => now()])->save();

            return $token;
        }, 3);
    }

    private function finishClaim(int $settingId, string $claim, ?string $errorCode): void
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        DB::connection($connection)->transaction(function () use ($settingId, $claim, $errorCode): void {
            $setting = FioBankAccountSetting::query()->whereKey($settingId)->where('sync_claim_token', $claim)->lockForUpdate()->first();
            if ($setting === null) {
                return;
            }
            $setting->forceFill([
                'sync_claim_token' => null,
                'sync_claimed_at' => null,
                'last_successful_sync_at' => $errorCode === null ? now() : $setting->last_successful_sync_at,
                'last_error_at' => $errorCode === null ? null : now(),
                'last_error_code' => $errorCode,
            ])->save();
        }, 3);
    }

    /** @param array{currency: ?string, iban: ?string, account_number: ?string, bank_code: ?string} $remote */
    private function assertAccount(BankAccount $account, array $remote): void
    {
        if ($remote['currency'] !== null && $remote['currency'] !== $account->currency) {
            throw new FioApiException('account_currency_mismatch');
        }
        if ($remote['iban'] !== null && $account->iban !== null
            && strtoupper(str_replace(' ', '', $remote['iban'])) !== strtoupper(str_replace(' ', '', $account->iban))) {
            throw new FioApiException('account_iban_mismatch');
        }
    }
}
