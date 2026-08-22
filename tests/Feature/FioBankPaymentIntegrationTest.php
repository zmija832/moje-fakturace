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
use App\Services\Business\InvoiceDraftEditor;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentReader;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_existing_unmatched_movement_is_retried_after_eligible_invoice_is_issued(): void
    {
        Event::fake([InvoicePaymentChanged::class]);
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft, $client, $account, $rate] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-rematch-12277');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('movement-12277', '100', 'CZK', '12277'),
        ], 'CZK', $account->iban), 200)]);
        $service = app(FioBankSyncService::class);

        $first = $service->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $transaction = BankTransaction::query()->sole();
        $this->assertSame(['new' => 1, 'duplicates' => 0, 'matched' => 0, 'unmatched' => 1], $this->matchingStats($first));
        $this->assertSame('unmatched', $transaction->status);
        $this->assertSame(0, InvoicePayment::query()->count());

        app(InvoiceDraftEditor::class)->update(
            $draft->uuid,
            1,
            (string) Str::uuid(),
            $this->invoicePayload($client->uuid, $account->uuid, $rate->uuid, '12277'),
        );
        $invoice = app(InvoiceIssuer::class)->issue($draft->uuid, 2, (string) Str::uuid());

        $second = $service->sync($setting->fresh()->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $transaction = $transaction->fresh();
        $payment = InvoicePayment::query()->sole();
        $this->assertSame(['new' => 0, 'duplicates' => 1, 'matched' => 1, 'unmatched' => 0], $this->matchingStats($second));
        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertSame('matched', $transaction->status);
        $this->assertSame($invoice->id, $transaction->matched_invoice_id);
        $this->assertSame($payment->id, $transaction->invoice_payment_id);

        $third = $service->sync($setting->fresh()->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $this->assertSame(['new' => 0, 'duplicates' => 1, 'matched' => 0, 'unmatched' => 0], $this->matchingStats($third));
        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertSame(1, InvoicePayment::query()->count());
        Event::assertDispatched(InvoicePaymentChanged::class, 1);
    }

    public function test_existing_ignored_movement_is_not_retried(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, , $account] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-ignored');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('ignored-repeat', '25', 'CZK', null),
        ], 'CZK', $account->iban), 200)]);
        $service = app(FioBankSyncService::class);
        $service->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $transaction = BankTransaction::query()->sole();
        $this->withSession($this->deliveryBusinessSession($business))->patch(route('bank-transactions.ignore', $transaction->uuid))->assertRedirect();

        $repeated = $service->sync($setting->fresh()->load('bankAccount'), BusinessDate::normalize('2026-08-21'));

        $this->assertSame(['new' => 0, 'duplicates' => 1, 'matched' => 0, 'unmatched' => 0], $this->matchingStats($repeated));
        $this->assertSame('ignored', $transaction->fresh()->status);
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    public function test_existing_unmatched_with_multiple_candidates_stays_unmatched(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$firstDraft, $client, $account, $rate] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        $payload = $this->invoicePayload($client->uuid, $account->uuid, $rate->uuid, '12277');
        app(InvoiceDraftEditor::class)->update($firstDraft->uuid, 1, (string) Str::uuid(), $payload);
        app(InvoiceIssuer::class)->issue($firstDraft->uuid, 2, (string) Str::uuid());
        $secondDraft = app(InvoiceDraftService::class)->create($payload);
        app(InvoiceIssuer::class)->issue($secondDraft->uuid, 1, (string) Str::uuid());
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-ambiguous');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('ambiguous-repeat', '100', 'CZK', '12277'),
        ], 'CZK', $account->iban), 200)]);
        $service = app(FioBankSyncService::class);

        $service->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $repeated = $service->sync($setting->fresh()->load('bankAccount'), BusinessDate::normalize('2026-08-21'));

        $this->assertSame(['new' => 0, 'duplicates' => 1, 'matched' => 0, 'unmatched' => 1], $this->matchingStats($repeated));
        $this->assertSame('unmatched', BankTransaction::query()->sole()->status);
        $this->assertSame(0, InvoicePayment::query()->count());
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
        $repeated = app(FioBankSyncService::class)->sync($setting->fresh()->load('bankAccount'), BusinessDate::normalize('2026-08-21'));

        $this->assertSame(2, $result['unmatched']);
        $this->assertSame(2, $repeated['duplicates']);
        $this->assertSame(2, $repeated['unmatched']);
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

    public function test_fully_paid_and_overpaid_invoices_are_not_automatic_match_candidates(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$paidInvoice, $client, $account, $rate] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $payments = app(InvoicePaymentService::class);
        $payment = [
            'currency' => 'CZK', 'paid_on' => '2026-08-21', 'payment_method' => 'bank_transfer',
            'reference' => null, 'variable_symbol' => '20260001', 'note' => null,
        ];
        $payments->record($paidInvoice->uuid, (string) Str::uuid(), $payment + ['amount' => '100']);

        $overpaidDraft = app(InvoiceDraftService::class)->create(
            $this->invoicePayload($client->uuid, $account->uuid, $rate->uuid, '20260002'),
        );
        $overpaidInvoice = app(InvoiceIssuer::class)->issue($overpaidDraft->uuid, 1, (string) Str::uuid());
        $payments->recordImported(
            $overpaidInvoice->uuid,
            (string) Str::uuid(),
            'existing-overpayment',
            $payment + ['amount' => '110', 'variable_symbol' => '20260002'],
            static function (): void {},
        );
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-paid-exclusion');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('paid-excluded', '25', 'CZK', '20260001'),
            $this->movement('overpaid-excluded', '25', 'CZK', '20260002'),
        ], 'CZK', $account->iban), 200)]);
        $result = app(FioBankSyncService::class)->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));

        $this->assertSame(0, $result['matched']);
        $this->assertSame(2, $result['unmatched']);
        $this->assertSame('unmatched', BankTransaction::query()->where('external_transaction_id', 'paid-excluded')->sole()->status);
        $this->assertSame('unmatched', BankTransaction::query()->where('external_transaction_id', 'overpaid-excluded')->sole()->status);
        $this->assertSame(2, InvoicePayment::query()->count());
    }

    public function test_duplicate_variable_symbol_chooses_the_only_invoice_with_an_outstanding_balance(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$firstDraft, $client, $account, $rate] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);
        $payload = $this->invoicePayload($client->uuid, $account->uuid, $rate->uuid, '12277');
        app(InvoiceDraftEditor::class)->update($firstDraft->uuid, 1, (string) Str::uuid(), $payload);
        $paidInvoice = app(InvoiceIssuer::class)->issue($firstDraft->uuid, 2, (string) Str::uuid());
        $unpaidDraft = app(InvoiceDraftService::class)->create($payload);
        $unpaidInvoice = app(InvoiceIssuer::class)->issue($unpaidDraft->uuid, 1, (string) Str::uuid());
        app(InvoicePaymentService::class)->record($paidInvoice->uuid, (string) Str::uuid(), [
            'amount' => '100', 'currency' => 'CZK', 'paid_on' => '2026-08-21',
            'payment_method' => 'bank_transfer', 'variable_symbol' => '12277',
        ]);
        $setting = app(FioBankAccountSettingService::class)->save($account->uuid, true, 'token-duplicate-vs-paid');
        Http::fake(['fioapi.fio.cz/*' => Http::response($this->statement([
            $this->movement('duplicate-vs-unpaid', '100', 'CZK', '12277'),
        ], 'CZK', $account->iban), 200)]);

        $result = app(FioBankSyncService::class)->sync($setting->load('bankAccount'), BusinessDate::normalize('2026-08-21'));
        $transaction = BankTransaction::query()->where('external_transaction_id', 'duplicate-vs-unpaid')->sole();

        $this->assertSame(1, $result['matched']);
        $this->assertSame('matched', $transaction->status);
        $this->assertSame($unpaidInvoice->id, $transaction->matched_invoice_id);
        $this->assertSame('paid', app(InvoicePaymentReader::class)->summary($paidInvoice->fresh())->status->value);
        $this->assertSame('paid', app(InvoicePaymentReader::class)->summary($unpaidInvoice->fresh())->status->value);
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

    /** @return array<string, mixed> */
    private function invoicePayload(string $clientUuid, string $accountUuid, string $rateUuid, string $variableSymbol): array
    {
        return [
            'customer_uuid' => $clientUuid, 'bank_account_uuid' => $accountUuid, 'currency' => 'CZK',
            'issued_on' => '2026-08-02', 'taxable_supply_on' => '2026-08-02', 'due_on' => '2026-08-16',
            'payment_method' => 'bank_transfer', 'variable_symbol' => $variableSymbol, 'note' => null,
            'invoice_discount_type' => 'none', 'invoice_discount_value' => '0',
            'items' => [[
                'position' => 1, 'description' => 'Bezpečná služba', 'quantity' => '1', 'unit' => 'ks',
                'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => '0', 'vat_rate_uuid' => $rateUuid,
            ]],
        ];
    }

    /** @param array<string, int|string> $stats @return array{new: int, duplicates: int, matched: int, unmatched: int} */
    private function matchingStats(array $stats): array
    {
        return [
            'new' => (int) $stats['new'],
            'duplicates' => (int) $stats['duplicates'],
            'matched' => (int) $stats['matched'],
            'unmatched' => (int) $stats['unmatched'],
        ];
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
