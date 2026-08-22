<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\BankTransaction;
use App\Models\Business\Invoice;
use App\Models\User;
use App\Services\Business\BankTransactionMatcher;
use App\Services\Business\InvoiceAggregateDeletion;
use App\Services\Business\InvoiceDeletionService;
use App\Services\Business\InvoiceDuplicator;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\TestCase;

class InvoiceDeletionFioTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_http_permanent_delete_preserves_fully_matched_fio_movement_as_unmatched(): void
    {
        [$admin, $business, $invoice, $account] = $this->environment();
        $transaction = $this->match($invoice, $account, '100', 'fio-delete-full');
        $paymentId = $transaction->invoice_payment_id;
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->delete(route('invoices.delete', $invoice->uuid), ['confirmation' => '1'])
            ->assertRedirect(route('invoices.index'));

        $this->assertDeletedAggregateAndUnmatchedTransaction('business_1', $invoice, $transaction, $paymentId);
    }

    #[DataProvider('singleFioPaymentCases')]
    public function test_partial_full_and_overpaid_fio_payment_can_be_permanently_deleted(string $amount): void
    {
        [, , $invoice, $account] = $this->environment();
        $transaction = $this->match($invoice, $account, $amount, 'fio-delete-'.$amount);
        $paymentId = $transaction->invoice_payment_id;

        app(InvoiceDeletionService::class)->delete($invoice->uuid);

        $this->assertDeletedAggregateAndUnmatchedTransaction('business_1', $invoice, $transaction, $paymentId);
    }

    public function test_multiple_fio_movements_are_all_unmatched_before_ledger_is_deleted(): void
    {
        [, , $invoice, $account] = $this->environment();
        $first = $this->match($invoice, $account, '40', 'fio-delete-multiple-a');
        $second = $this->match($invoice, $account, '60', 'fio-delete-multiple-b');

        app(InvoiceDeletionService::class)->delete($invoice->uuid);

        $this->assertSame(0, DB::connection('business_1')->table('invoice_payments')->where('invoice_id', $invoice->id)->count());
        foreach ([$first, $second] as $transaction) {
            $this->assertUnmatched('business_1', $transaction->uuid);
        }
    }

    public function test_mixed_manual_and_fio_payments_delete_ledger_but_preserve_bank_movement(): void
    {
        [, , $invoice, $account] = $this->environment();
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '25', 'currency' => 'CZK', 'paid_on' => '2026-08-22', 'payment_method' => 'bank_transfer',
        ]);
        $transaction = $this->match($invoice, $account, '75', 'fio-delete-mixed');

        app(InvoiceDeletionService::class)->delete($invoice->uuid);

        $this->assertSame(0, DB::connection('business_1')->table('invoice_payments')->where('invoice_id', $invoice->id)->count());
        $this->assertUnmatched('business_1', $transaction->uuid);
    }

    public function test_delete_does_not_change_bank_transaction_matched_to_another_invoice(): void
    {
        [, , $invoice, $account] = $this->environment();
        $deletedTransaction = $this->match($invoice, $account, '25', 'fio-delete-target');
        $otherDraft = app(InvoiceDuplicator::class)->duplicate($invoice);
        $otherInvoice = app(InvoiceIssuer::class)->issue($otherDraft->uuid, $otherDraft->version, (string) Str::uuid());
        $otherTransaction = $this->match($otherInvoice, $account, '25', 'fio-delete-unrelated');
        $unmatched = $this->movement($invoice, $account, '10', 'fio-delete-unmatched');
        $ignored = $this->movement($invoice, $account, '10', 'fio-delete-ignored');
        DB::connection('business_1')->table('bank_transactions')->where('id', $ignored->id)->update(['status' => 'ignored']);

        app(InvoiceDeletionService::class)->delete($invoice->uuid);

        $this->assertUnmatched('business_1', $deletedTransaction->uuid);
        $unchanged = DB::connection('business_1')->table('bank_transactions')->where('uuid', $otherTransaction->uuid)->sole();
        $this->assertSame('matched', $unchanged->status);
        $this->assertSame($otherInvoice->id, (int) $unchanged->matched_invoice_id);
        $this->assertNotNull($unchanged->invoice_payment_id);
        $this->assertNotNull($unchanged->matched_at);
        $this->assertSame('unmatched', DB::connection('business_1')->table('bank_transactions')->where('id', $unmatched->id)->value('status'));
        $this->assertSame('ignored', DB::connection('business_1')->table('bank_transactions')->where('id', $ignored->id)->value('status'));
    }

    public function test_delete_is_tenant_local_and_never_changes_other_business_bank_transaction(): void
    {
        [, $firstBusiness, $invoice] = $this->environment();
        [, $secondBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($secondBusiness);
        [$secondInvoice, , $secondAccount] = $this->createIssuedInvoice();
        $secondTransaction = $this->match($secondInvoice, $secondAccount, '25', 'fio-delete-other-tenant');

        app(ActiveBusinessContext::class)->set($firstBusiness);
        app(InvoiceDeletionService::class)->delete($invoice->uuid);

        $unchanged = DB::connection('business_2')->table('bank_transactions')->where('uuid', $secondTransaction->uuid)->sole();
        $this->assertSame('matched', $unchanged->status);
        $this->assertSame($secondInvoice->id, (int) $unchanged->matched_invoice_id);
    }

    public function test_later_delete_failure_rolls_back_bank_unmatch_and_payment_delete(): void
    {
        [, , $invoice, $account] = $this->environment();
        $transaction = $this->match($invoice, $account, '100', 'fio-delete-rollback');
        $paymentId = $transaction->invoice_payment_id;
        $aggregate = Mockery::mock(InvoiceAggregateDeletion::class);
        $aggregate->shouldReceive('deleteRevisions')->once()->andThrow(new RuntimeException('Simulated aggregate failure'));
        app()->instance(InvoiceAggregateDeletion::class, $aggregate);

        try {
            app(InvoiceDeletionService::class)->delete($invoice->uuid);
            $this->fail('Pozdější chyba musí permanentní delete vrátit zpět.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated aggregate failure', $exception->getMessage());
        }

        $this->assertTrue(DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->exists());
        $this->assertTrue(DB::connection('business_1')->table('invoice_payments')->where('id', $paymentId)->exists());
        $matched = DB::connection('business_1')->table('bank_transactions')->where('uuid', $transaction->uuid)->sole();
        $this->assertSame('matched', $matched->status);
        $this->assertSame($invoice->id, (int) $matched->matched_invoice_id);
        $this->assertSame($paymentId, (int) $matched->invoice_payment_id);
        $this->assertNotNull($matched->matched_at);
    }

    public static function singleFioPaymentCases(): array
    {
        return [
            'partial' => ['40'],
            'full' => ['100'],
            'overpaid' => ['110'],
        ];
    }

    /** @return array{User,Business,Invoice,BankAccount} */
    private function environment(): array
    {
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        $this->actingAs($admin);
        [$invoice, , $account] = $this->createIssuedInvoice();

        return [$admin, $business, $invoice, $account];
    }

    private function match(Invoice $invoice, BankAccount $account, string $amount, string $externalId): BankTransaction
    {
        $transaction = $this->movement($invoice, $account, $amount, $externalId);

        return app(BankTransactionMatcher::class)->matchManually($transaction->uuid, $invoice->uuid);
    }

    private function movement(Invoice $invoice, BankAccount $account, string $amount, string $externalId): BankTransaction
    {
        $transaction = new BankTransaction;
        $transaction->forceFill([
            'bank_account_id' => $account->id,
            'source' => 'fio',
            'external_transaction_id' => $externalId,
            'booked_on' => '2026-08-22',
            'amount' => $amount,
            'currency' => 'CZK',
            'variable_symbol' => $invoice->variable_symbol,
            'status' => 'unmatched',
            'imported_at' => now(),
        ])->save();

        return $transaction;
    }

    private function assertDeletedAggregateAndUnmatchedTransaction(string $connection, Invoice $invoice, BankTransaction $transaction, int $paymentId): void
    {
        $this->assertFalse(DB::connection($connection)->table('invoices')->where('id', $invoice->id)->exists());
        $this->assertFalse(DB::connection($connection)->table('invoice_payments')->where('id', $paymentId)->exists());
        $this->assertUnmatched($connection, $transaction->uuid);
        $this->assertSame(1, DB::connection($connection)->table('audit_logs')
            ->where('event', 'invoice.deleted')->where('auditable_uuid', $invoice->uuid)->count());
    }

    private function assertUnmatched(string $connection, string $transactionUuid): void
    {
        $transaction = DB::connection($connection)->table('bank_transactions')->where('uuid', $transactionUuid)->sole();
        $this->assertSame('unmatched', $transaction->status);
        $this->assertNull($transaction->matched_invoice_id);
        $this->assertNull($transaction->invoice_payment_id);
        $this->assertNull($transaction->matched_at);
        $this->assertNull($transaction->unmatched_reason);
    }
}
