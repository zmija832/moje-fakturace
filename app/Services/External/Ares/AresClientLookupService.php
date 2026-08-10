<?php

namespace App\Services\External\Ares;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class AresClientLookupService
{
    /**
     * @return array{
     *     subject: array{
     *         company_name: ?string,
     *         registration_number: string,
     *         tax_id: ?string,
     *         street: ?string,
     *         city: ?string,
     *         postal_code: ?string,
     *         country_code: ?string
     *     },
     *     warnings: list<string>
     * }
     */
    public function findByIco(string $ico): array
    {
        $ttl = max(1, (int) config('services.ares.cache_ttl_seconds', 21600));

        return Cache::remember("ares:subject:v1:{$ico}", $ttl, fn (): array => $this->request($ico));
    }

    /** @return array{subject: array<string, ?string>, warnings: list<string>} */
    private function request(string $ico): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.ares.base_url'), '/'))
                ->acceptJson()
                ->connectTimeout(max(1, (int) config('services.ares.connect_timeout_seconds', 2)))
                ->timeout(max(1, (int) config('services.ares.timeout_seconds', 5)))
                ->get("ekonomicke-subjekty/{$ico}");
        } catch (ConnectionException $exception) {
            Log::warning('ARES lookup connection failed.', ['exception' => $exception::class]);

            throw AresLookupException::unavailable();
        }

        if ($response->notFound()) {
            throw AresLookupException::notFound();
        }

        if (! $response->successful()) {
            Log::warning('ARES lookup returned an unsuccessful response.', [
                'status' => $response->status(),
            ]);

            throw AresLookupException::unavailable();
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('ARES lookup returned malformed JSON.');

            throw AresLookupException::unavailable();
        }

        return $this->normalize($ico, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{subject: array<string, ?string>, warnings: list<string>}
     */
    private function normalize(string $requestedIco, array $payload): array
    {
        $responseIco = $this->digits($payload['ico'] ?? null, 8);

        if ($responseIco !== $requestedIco) {
            Log::warning('ARES lookup returned a mismatched subject.');

            throw AresLookupException::unavailable();
        }

        $address = is_array($payload['sidlo'] ?? null) ? $payload['sidlo'] : [];
        $street = $this->street($address);
        $city = $this->text($address['nazevObce'] ?? null, 128);
        $postalCode = $this->postalCode($address);
        $countryCode = $this->countryCode($address['kodStatu'] ?? null);
        $companyName = $this->text($payload['obchodniJmeno'] ?? null, 255);
        $taxId = $this->taxId($payload['dic'] ?? null);
        $warnings = [];

        if ($street === null || $city === null || $postalCode === null || $countryCode === null) {
            $warnings[] = 'ARES nevrátil úplnou fakturační adresu. Chybějící údaje doplňte ručně.';
        }

        if ($companyName === null) {
            $warnings[] = 'ARES nevrátil obchodní název. Doplňte jej ručně.';
        }

        return [
            'subject' => [
                'company_name' => $companyName,
                'registration_number' => $requestedIco,
                'tax_id' => $taxId,
                'street' => $street,
                'city' => $city,
                'postal_code' => $postalCode,
                'country_code' => $countryCode,
            ],
            'warnings' => $warnings,
        ];
    }

    /** @param array<string, mixed> $address */
    private function street(array $address): ?string
    {
        $name = $this->text($address['nazevUlice'] ?? null, 200)
            ?? $this->text($address['nazevCastiObce'] ?? null, 200);
        $number = $this->text($address['cisloDoAdresy'] ?? null, 40);

        if ($number === null) {
            $houseNumber = $this->digits($address['cisloDomovni'] ?? null, 10);
            $orientationNumber = $this->digits($address['cisloOrientacni'] ?? null, 10);
            $orientationLetter = $this->text($address['cisloOrientacniPismeno'] ?? null, 4);

            if ($houseNumber !== null) {
                $number = $houseNumber;
                if ($orientationNumber !== null) {
                    $number .= '/'.$orientationNumber.$orientationLetter;
                }
            }
        }

        if ($name !== null && $number !== null) {
            return $this->text($name.' '.$number, 255);
        }

        if ($name !== null) {
            return $name;
        }

        return $number === null ? null : $this->text('č.p. '.$number, 255);
    }

    /** @param array<string, mixed> $address */
    private function postalCode(array $address): ?string
    {
        $value = $address['pscTxt'] ?? $address['psc'] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $postalCode = preg_replace('/\s+/u', '', trim((string) $value));

        return is_string($postalCode) && preg_match('/^\d{5}$/D', $postalCode) === 1
            ? $postalCode
            : null;
    }

    private function countryCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return match (trim((string) $value)) {
            '203' => 'CZ',
            '703' => 'SK',
            default => null,
        };
    }

    private function taxId(mixed $value): ?string
    {
        $taxId = $this->text($value, 32);

        return $taxId !== null && preg_match('/^CZ\d{8,10}$/D', $taxId) === 1
            ? $taxId
            : null;
    }

    private function digits(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = trim((string) $value);

        return strlen($digits) <= $maxLength && preg_match('/^\d+$/D', $digits) === 1
            ? $digits
            : null;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string) $value));
        $text = is_string($text) ? preg_replace('/\s+/u', ' ', $text) : null;

        if (! is_string($text) || $text === '') {
            return null;
        }

        return mb_substr($text, 0, $maxLength);
    }
}
