<?php

namespace App\Domain\Banking;

use App\Enums\BankTransactionUnmatchedReason;

final readonly class BankTransactionMatchResult
{
    private function __construct(
        public bool $matched,
        public ?BankTransactionUnmatchedReason $reason,
    ) {}

    public static function matched(): self
    {
        return new self(true, null);
    }

    public static function unmatched(BankTransactionUnmatchedReason $reason): self
    {
        return new self(false, $reason);
    }

    public static function skipped(): self
    {
        return new self(false, null);
    }
}
