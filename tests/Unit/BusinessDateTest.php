<?php

namespace Tests\Unit;

use App\Services\Business\BusinessDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessDateTest extends TestCase
{
    #[DataProvider('calendarDifferences')]
    public function test_calendar_days_ignore_offsets_dst_and_boundaries(string $from, string $to, int $expected): void
    {
        $dates = new BusinessDate;

        $this->assertSame($expected, $dates->daysBetween(
            CarbonImmutable::parse($from, 'Europe/Prague'),
            CarbonImmutable::parse($to, 'Europe/Prague'),
        ));
    }

    public static function calendarDifferences(): array
    {
        return [
            'same date' => ['2026-08-18 00:00:00', '2026-08-18 23:59:59', 0],
            'next date' => ['2026-08-18 00:00:00', '2026-08-19 00:00:00', 1],
            'seven dates' => ['2026-08-18 00:00:00', '2026-08-25 00:00:00', 7],
            'spring DST' => ['2026-03-29 00:00:00', '2026-03-30 00:00:00', 1],
            'autumn DST' => ['2026-10-25 00:00:00', '2026-10-26 00:00:00', 1],
            'month boundary' => ['2026-08-31 00:00:00', '2026-09-01 00:00:00', 1],
            'year boundary' => ['2026-12-31 00:00:00', '2027-01-01 00:00:00', 1],
        ];
    }

    public function test_add_days_produces_a_stable_date_only_value(): void
    {
        $dates = new BusinessDate;

        $this->assertSame('2026-08-19', $dates->addDays('2026-08-18', 1)->format('Y-m-d'));
        $this->assertSame('2026-08-25', $dates->addDays('2026-08-18', 7)->format('Y-m-d'));
    }
}
