<?php

namespace App\Services\Business;

use App\Models\Business\CompanySetting;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class BusinessDate
{
    public function today(): CarbonImmutable
    {
        $timezone = CompanySetting::query()
            ->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->value('timezone') ?: config('app.timezone');

        return self::normalize(CarbonImmutable::now($timezone)->format('Y-m-d'));
    }

    public static function normalize(DateTimeInterface|string $date): CarbonImmutable
    {
        $value = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;
        $normalized = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        if ($normalized === false || $normalized->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Neplatné kalendářní datum.');
        }

        return $normalized;
    }

    public static function daysBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): int
    {
        return (int) self::normalize($from)->diff(self::normalize($to))->format('%r%a');
    }

    public static function addDays(DateTimeInterface|string $date, int $days): CarbonImmutable
    {
        return self::normalize($date)->addDays($days);
    }
}
