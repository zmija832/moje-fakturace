<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Events\InvoicePaymentChanged;
use App\Models\Business\BankAccount;
use App\Models\Business\BankTransaction;
use App\Models\Business\FioBankAccountSetting;
use App\Models\Business\InvoicePayment;
use App\Services\Business\BankTransactionMatcher;
use App\Services\Business\BusinessDate;
use App\Services\Business\FioBankAccountSettingService;
use App\Services\Business\FioBankSyncService;
use App\Services\Business\InvoicePaymentReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class FioBankPaymentIntegrationTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_token_is_encrypted_hidden_and_empty_replacement_preserves_it(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, , $account] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);

        $service = app(FioBankAccountSettingService::class);
        $setting = $service->save($account->uuid, true, 'secret-fio-token-A');
        $raw = DB::connection('business_1')->table('fio_bank_account_settings')->where('id', $setting->id)->value('encrypted_token');
        $this->assertNotSame('secret-fio-token-A', $raw);
        $this->assertStringNotContainsString('secret-fio-token-A', json_encode($setting, JSON_THROW_ON_ERROR));

        $service->save($account->uuid, true, '');
        $this->assertSame('secret-fio-token-A', $setting->fresh()->encrypted_token);
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'fio_integration.token_replaced')->count());
    }

    public function test_sync_imports_matches_once_and_uses_existing_payment_workflow(): void
    {
        Event::fake([InvoicePaymentChanged::class]);
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, , $account] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-CZK-123');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('900001', '100.0000', 'CZK', '20260001'),
        ], 'CZK', $account->iban), 200)]);

        $service = app(FioBankSyncService::class);
        $first = $service->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $second = $service->sync($setting->fresh()->load('bankAccount'), BusinessDate::normalize('2026-08-21'));

        $this->assertSame(1, $first['matched']);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertSame(1, InvoicePayment::query()->count());
        $transaction = BankTransaction::query()->firstOrFail();
        $this->assertSame('matched', $transaction->status);
        $this->assertSame($invoice->id, $transaction->matched_invoice_id);
        $this->assertSame('future_bank_import', $transaction->payment->source->value);
        Event::assertDispatched(InvoicePaymentChanged::class, 1);
    }

    public function test_missing_vs_wrong_currency_and_wrong_target_account_stay_unmatched(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, , $account] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-CZK-456');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('900002', '10', 'CZK', null),
            $this->movement('900003', '10', 'EUR', '20260001'),
        ], 'CZK', $account->iban), 200)]);

        $result = app(FioBankSyncService::class)->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));

        $this->assertSame(2, $result['unmatched']);
        $this->assertSame(0, InvoicePayment::query()->count());
        $this->assertSame(2, BankTransaction::query()->where('status', 'unmatched')->count());
    }

    public function test_manual_match_and_ignore_are_idempotent_domain_operations(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, , $account] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $first = $this->bankTransaction($account->id, 'manual-1', '25');
        $second = $this->bankTransaction($account->id, 'ignore-1', '5');

        app(BankTransactionMatcher::class)->matchManually($first->uuid, $invoice->uuid);
        $this->withSession($this->deliveryBusinessSession($business))->patch(route('bank-transactions.ignore', $second->uuid))->assertRedirect();

        $this->assertSame('matched', $first->fresh()->status);
        $this->assertSame('ignored', $second->fresh()->status);
        $this->assertSame(1, InvoicePayment::query()->count());
    }

    public function test_partial_payments_and_overpayment_use_the_authoritative_ledger(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, , $account] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-overpayment');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('part-1', '40', 'CZK', '20260001'),
            $this->movement('part-2', '70', 'CZK', '20260001'),
        ], 'CZK', $account->iban), 200)]);

        $result = app(FioBankSyncService::class)->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $summary = app(InvoicePaymentReader::class)->summary($invoice->fresh());

        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, InvoicePayment::query()->count());
        $this->assertSame('110.0000', $summary->paidTotal);
        $this->assertSame('overpaid', $summary->status->value);
        $this->assertSame('10.0000', $summary->overpaymentTotal);
    }

    public function test_one_fio_account_failure_does_not_stop_another_account(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, , $czk] = $this->createIssuedInvoice(false);
        $eur = new BankAccount;
        $eur->forceFill(['name' => 'Fio EUR', 'iban' => 'CZ1200000000000000000001', 'currency' => 'EUR', 'is_active' => true, 'sort_order' => 1])->save();
        $this->actingAs($admin);
        app(FioBankAccountSettingService::class)->save($czk->uuid, true, 'broken-token');
        app(FioBankAccountSettingService::class)->save($eur->uuid, true, 'working-token');
        Http::fake([
            '*broken-token*' => Http::response([], 500),
            '*working-token*' => Http::response($this->statement([$this->movement('eur-1', '15', 'EUR', null)], 'EUR', $eur->iban), 200),
        ]);

        $result = app(FioBankSyncService::class)->syncAll(BusinessDate::normalize('2026-08-21'));

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, BankTransaction::query()->where('currency', 'EUR')->count());
        $this->assertSame(1, FioBankAccountSetting::query()->whereNotNull('last_error_at')->count());
        Http::assertSentCount(2);
    }

    public function test_edit_ui_never_returns_token_and_settings_are_tenant_isolated(): void
    {
        [$admin, $businessOne] = $this->deliveryMembership();
        [, $businessTwo] = $this->deliveryMembership('admin', BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessOne);
        [, , $account] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        app(FioBankAccountSettingService::class)->save($account->uuid, true, 'never-render-this-token');

        $this->withSession($this->deliveryBusinessSession($businessOne))
            ->get(route('bank-accounts.edit', $account->uuid))
            ->assertOk()
            ->assertDontSee('never-render-this-token');
        $this->withSession($this->deliveryBusinessSession($businessOne))
            ->from(route('bank-accounts.edit', $account->uuid))
            ->put(route('bank-accounts.fio.update', $account->uuid), ['is_enabled' => 1, 'token' => 'short'])
            ->assertSessionHasErrors('token')
            ->assertSessionMissing('_old_input.token');

        app(ActiveBusinessContext::class)->set($businessTwo);
        $this->assertSame(0, FioBankAccountSetting::query()->count());
    }

    private function bankTransaction(int $accountId, string $id, string $amount): BankTransaction
    {
        $transaction = new BankTransaction;
        $transaction->forceFill(['bank_account_id' => $accountId, 'source' => 'fio', 'external_transaction_id' => $id, 'booked_on' => '2026-08-21', 'amount' => $amount, 'currency' => 'CZK', 'variable_symbol' => '20260001', 'status' => 'unmatched', 'imported_at' => now()])->save();

        return $transaction->load('bankAccount');
    }

    /** @param list<array<string, mixed>> $movements */
    private function statement(array $movements, string $currency, ?string $iban): array
    {
        return ['accountStatement' => ['info' => ['currency' => $currency, 'iban' => $iban], 'transactionList' => ['transaction' => $movements]]];
    }

    private function movement(string $id, string $amount, string $currency, ?string $vs): array
    {
        return [
            'column22' => ['value' => $id], 'column0' => ['value' => '2026-08-21+02:00'],
            'column1' => ['value' => $amount], 'column14' => ['value' => $currency],
            'column5' => ['value' => $vs], 'column2' => ['value' => '123456789'],
            'column3' => ['value' => '2010'], 'column10' => ['value' => 'Klient'],
            'column16' => ['value' => 'Úhrada faktury'], 'column8' => ['value' => 'Platba převodem uvnitř banky'],
        ];
    }
}
