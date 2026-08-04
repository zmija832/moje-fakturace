<?php

namespace Tests\Unit;

use App\Domain\Invoices\InvoicePaymentSummary;
use App\Enums\InvoicePaymentStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoicePaymentSummaryTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_statuses_are_derived_with_exact_decimal_math(array $entries, string $paid, string $remaining, InvoicePaymentStatus $status): void
    {
        $summary = InvoicePaymentSummary::fromLedger('100.0000', $entries);

        self::assertSame($paid, $summary->paidTotal);
        self::assertSame($remaining, $summary->remainingTotal);
        self::assertSame($status, $summary->status);
    }

    public static function statuses(): array
    {
        return [
            'unpaid' => [[], '0.0000', '100.0000', InvoicePaymentStatus::Unpaid],
            'partial' => [[['payment_type' => 'payment', 'amount' => '33.3333']], '33.3333', '66.6667', InvoicePaymentStatus::PartiallyPaid],
            'paid in two parts' => [[['payment_type' => 'payment', 'amount' => '40'], ['payment_type' => 'payment', 'amount' => '60']], '100.0000', '0.0000', InvoicePaymentStatus::Paid],
            'overpaid' => [[['payment_type' => 'payment', 'amount' => '100.0001']], '100.0001', '-0.0001', InvoicePaymentStatus::Overpaid],
            'partial reversal' => [[['payment_type' => 'payment', 'amount' => '80'], ['payment_type' => 'reversal', 'amount' => '12.3456']], '67.6544', '32.3456', InvoicePaymentStatus::PartiallyPaid],
            'full reversal' => [[['payment_type' => 'payment', 'amount' => '100'], ['payment_type' => 'reversal', 'amount' => '100']], '0.0000', '100.0000', InvoicePaymentStatus::Unpaid],
        ];
    }

    public function test_overdue_requires_a_positive_remaining_total(): void
    {
        $due = new CarbonImmutable('2026-08-01');
        $today = new CarbonImmutable('2026-08-04');

        self::assertTrue(InvoicePaymentSummary::fromTotals('100', '99.9999', $due, $today)->isOverdue);
        self::assertFalse(InvoicePaymentSummary::fromTotals('100', '100', $due, $today)->isOverdue);
        self::assertFalse(InvoicePaymentSummary::fromTotals('100', '101', $due, $today)->isOverdue);
    }

    public function test_float_and_negative_ledger_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoicePaymentSummary::fromLedger('100', [
            ['payment_type' => 'payment', 'amount' => 10.5],
        ]);
    }

    public function test_reversal_cannot_make_paid_total_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoicePaymentSummary::fromLedger('100', [
            ['payment_type' => 'payment', 'amount' => '10'],
            ['payment_type' => 'reversal', 'amount' => '10.0001'],
        ]);
    }
}
