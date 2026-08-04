<?php

namespace App\Domain\Invoices;

use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoicePaymentType;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class InvoicePaymentSummary
{
    public function __construct(
        public string $grandTotal,
        public string $paidTotal,
        public string $remainingTotal,
        public string $overpaymentTotal,
        public InvoicePaymentStatus $status,
        public bool $isOverdue,
    ) {}

    /** @param iterable<array{payment_type: InvoicePaymentType|string, amount: string|int}> $entries */
    public static function fromLedger(
        mixed $grandTotal,
        iterable $entries,
        ?DateTimeInterface $dueOn = null,
        ?DateTimeInterface $today = null,
    ): self {
        $paidTotal = InvoiceDecimal::money(0);

        foreach ($entries as $entry) {
            $type = $entry['payment_type'] instanceof InvoicePaymentType
                ? $entry['payment_type']
                : InvoicePaymentType::from($entry['payment_type']);
            $amount = self::positiveMoney($entry['amount']);
            $paidTotal = $type === InvoicePaymentType::Payment
                ? InvoiceDecimal::add($paidTotal, $amount)
                : InvoiceDecimal::subtract($paidTotal, $amount);

        }

        if (InvoiceDecimal::compare($paidTotal, '0') < 0) {
            throw new InvalidArgumentException('Platební ledger nesmí vytvořit zápornou uhrazenou částku.');
        }

        return self::fromTotals($grandTotal, $paidTotal, $dueOn, $today);
    }

    public static function fromTotals(
        mixed $grandTotal,
        mixed $paidTotal,
        ?DateTimeInterface $dueOn = null,
        ?DateTimeInterface $today = null,
    ): self {
        $grandTotal = InvoiceDecimal::money($grandTotal);
        $paidTotal = InvoiceDecimal::money($paidTotal);
        if (InvoiceDecimal::compare($grandTotal, '0') < 0 || InvoiceDecimal::compare($paidTotal, '0') < 0) {
            throw new InvalidArgumentException('Souhrn platby nesmí obsahovat záporný základ ani uhrazenou částku.');
        }

        $remaining = InvoiceDecimal::subtract($grandTotal, $paidTotal);
        $status = match (true) {
            InvoiceDecimal::compare($paidTotal, '0') === 0 => InvoicePaymentStatus::Unpaid,
            InvoiceDecimal::compare($paidTotal, $grandTotal) < 0 => InvoicePaymentStatus::PartiallyPaid,
            InvoiceDecimal::compare($paidTotal, $grandTotal) === 0 => InvoicePaymentStatus::Paid,
            default => InvoicePaymentStatus::Overpaid,
        };
        $overpayment = InvoiceDecimal::compare($remaining, '0') < 0
            ? InvoiceDecimal::absolute($remaining)
            : InvoiceDecimal::money(0);
        $isOverdue = $dueOn !== null
            && $today !== null
            && $dueOn->format('Y-m-d') < $today->format('Y-m-d')
            && InvoiceDecimal::compare($remaining, '0') > 0;

        return new self($grandTotal, $paidTotal, $remaining, $overpayment, $status, $isOverdue);
    }

    private static function positiveMoney(mixed $amount): string
    {
        $amount = InvoiceDecimal::money($amount);
        if (InvoiceDecimal::compare($amount, '0') <= 0) {
            throw new InvalidArgumentException('Částka platebního záznamu musí být kladná.');
        }

        return $amount;
    }
}
