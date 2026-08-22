<?php

namespace App\Services\Business;

use App\Domain\Banking\FioApiException;
use App\Domain\Invoices\InvoiceDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class FioApiClient
{
    /** @return array{account: array{currency: ?string, iban: ?string, account_number: ?string, bank_code: ?string}, transactions: list<array<string, ?string>>, invalid_count: int} */
    public function transactions(string $token, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(12)->get(
                'https://fioapi.fio.cz/v1/rest/periods/'.rawurlencode($token).'/'.$from->format('Y-m-d').'/'.$to->format('Y-m-d').'/transactions.json',
            );
        } catch (ConnectionException) {
            throw new FioApiException('connection_failed');
        } catch (Throwable) {
            throw new FioApiException('request_failed');
        }

        if (! $response->successful()) {
            throw new FioApiException('http_'.$response->status());
        }

        $body = preg_replace_callback(
            '/("value"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/',
            static fn (array $match): string => $match[1].'"'.$match[2].'"',
            $response->body(),
        );
        try {
            $payload = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new FioApiException('invalid_json');
        }

        $statement = $payload['accountStatement'] ?? null;
        if (! is_array($statement) || ! is_array($statement['info'] ?? null)) {
            throw new FioApiException('invalid_structure');
        }

        $info = $statement['info'];
        $rows = $statement['transactionList']['transaction'] ?? [];
        if ($rows === null) {
            $rows = [];
        }
        if (! is_array($rows)) {
            throw new FioApiException('invalid_structure');
        }

        $transactions = [];
        $invalid = 0;
        foreach ($rows as $row) {
            try {
                $normalized = $this->normalizeTransaction($row);
                if (InvoiceDecimal::compare($normalized['amount'], '0') > 0) {
                    $transactions[] = $normalized;
                }
            } catch (Throwable) {
                $invalid++;
            }
        }

        return [
            'account' => [
                'currency' => $this->optional($info['currency'] ?? null, 3, uppercase: true),
                'iban' => $this->optional($info['iban'] ?? null, 34, uppercase: true),
                'account_number' => $this->optional($info['accountId'] ?? null, 64),
                'bank_code' => $this->optional($info['bankId'] ?? null, 16),
            ],
            'transactions' => $transactions,
            'invalid_count' => $invalid,
        ];
    }

    /** @return array<string, ?string> */
    private function normalizeTransaction(mixed $row): array
    {
        if (! is_array($row)) {
            throw new FioApiException('invalid_transaction');
        }
        $id = $this->required($this->column($row, 22), 128);
        $dateValue = $this->required($this->column($row, 0), 64);
        $date = substr($dateValue, 0, 10);
        if (CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
            throw new FioApiException('invalid_transaction_date');
        }
        $amount = InvoiceDecimal::money($this->required($this->column($row, 1), 64));
        $currency = $this->required($this->column($row, 14), 3, uppercase: true);
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new FioApiException('invalid_transaction_currency');
        }

        return [
            'external_transaction_id' => $id,
            'booked_on' => $date,
            'amount' => $amount,
            'currency' => $currency,
            'variable_symbol' => $this->optional($this->column($row, 5), 20),
            'counterparty_account' => $this->optional($this->column($row, 2), 64),
            'counterparty_bank_code' => $this->optional($this->column($row, 3), 16),
            'counterparty_name' => $this->optional($this->column($row, 10), 255),
            'message' => $this->optional($this->column($row, 16), 2000),
            'transaction_type' => $this->optional($this->column($row, 8), 128),
        ];
    }

    private function column(array $row, int $number): mixed
    {
        $column = $row['column'.$number] ?? null;

        return is_array($column) ? ($column['value'] ?? null) : null;
    }

    private function required(mixed $value, int $max, bool $uppercase = false): string
    {
        $value = $this->optional($value, $max, $uppercase);
        if ($value === null) {
            throw new FioApiException('missing_transaction_value');
        }

        return $value;
    }

    private function optional(mixed $value, int $max, bool $uppercase = false): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($uppercase) {
            $value = strtoupper($value);
        }

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
