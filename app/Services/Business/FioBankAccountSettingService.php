<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Models\Business\BankAccount;
use App\Models\Business\FioBankAccountSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FioBankAccountSettingService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function save(string $accountUuid, bool $enabled, ?string $newToken): FioBankAccountSetting
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($accountUuid, $enabled, $newToken): FioBankAccountSetting {
            $account = BankAccount::query()->where('uuid', $accountUuid)->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            $setting = FioBankAccountSetting::query()->where('bank_account_id', $account->id)->lockForUpdate()->first();
            $wasEnabled = (bool) ($setting?->is_enabled ?? false);
            $hadToken = filled($setting?->encrypted_token);
            $tokenChanged = $newToken !== null && trim($newToken) !== '';

            if ($enabled && ! $account->is_active) {
                throw ValidationException::withMessages(['is_enabled' => 'Fio synchronizaci lze zapnout pouze u aktivního účtu.']);
            }
            if ($enabled && ! $hadToken && ! $tokenChanged) {
                throw ValidationException::withMessages(['token' => 'Pro zapnutí Fio synchronizace je nutné uložit API token.']);
            }

            $setting ??= new FioBankAccountSetting;
            $setting->bank_account_id = $account->id;
            $setting->is_enabled = $enabled;
            if ($tokenChanged) {
                $setting->encrypted_token = trim((string) $newToken);
            }
            $setting->save();

            if ($tokenChanged) {
                $this->auditWriter->write(
                    BusinessAuditEvent::FioTokenReplaced,
                    BusinessAuditableType::FioBankAccountSetting,
                    $setting->uuid,
                    null,
                    ['token_changed' => true],
                    ['token_changed'],
                    BusinessAuditableType::BankAccount,
                    $account->uuid,
                );
            }
            if ($wasEnabled !== $enabled) {
                $this->auditWriter->write(
                    $enabled ? BusinessAuditEvent::FioIntegrationEnabled : BusinessAuditEvent::FioIntegrationDisabled,
                    BusinessAuditableType::FioBankAccountSetting,
                    $setting->uuid,
                    ['is_enabled' => $wasEnabled],
                    ['is_enabled' => $enabled],
                    ['is_enabled'],
                    BusinessAuditableType::BankAccount,
                    $account->uuid,
                );
            }

            return $setting->refresh();
        }, 3);
    }
}
