<?php

namespace App\Domain\DocumentSequences;

use App\Enums\DocumentSequenceResetPeriod;
use App\Enums\DocumentSequenceYearFormat;
use App\Models\Business\DocumentSequence;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

class DocumentNumberFormatter
{
    public function period(DocumentSequence $sequence, DateTimeInterface $documentDate): string
    {
        return $sequence->reset_period === DocumentSequenceResetPeriod::Yearly
            ? $documentDate->format('Y')
            : DocumentSequenceResetPeriod::Never->value;
    }

    public function format(
        DocumentSequence $sequence,
        int $sequenceNumber,
        DateTimeInterface $documentDate,
    ): string {
        if ($sequenceNumber < 1 || strlen((string) $sequenceNumber) > $sequence->sequence_digits) {
            throw ValidationException::withMessages([
                'sequence' => 'Číselná řada vyčerpala dostupný počet číslic.',
            ]);
        }

        $year = match ($sequence->year_format) {
            DocumentSequenceYearFormat::None => '',
            DocumentSequenceYearFormat::TwoDigits => $documentDate->format('y'),
            DocumentSequenceYearFormat::FourDigits => $documentDate->format('Y'),
        };

        return $sequence->prefix
            .$year
            .str_pad((string) $sequenceNumber, $sequence->sequence_digits, '0', STR_PAD_LEFT)
            .$sequence->suffix;
    }
}
