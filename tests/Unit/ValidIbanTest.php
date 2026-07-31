<?php

namespace Tests\Unit;

use App\Rules\ValidIban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidIbanTest extends TestCase
{
    #[DataProvider('validIbans')]
    public function test_accepts_valid_iban(string $iban): void
    {
        $failed = false;

        (new ValidIban)->validate('iban', $iban, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    #[DataProvider('invalidIbans')]
    public function test_rejects_invalid_iban(string $iban): void
    {
        $failed = false;

        (new ValidIban)->validate('iban', $iban, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validIbans(): array
    {
        return [
            'Czech Republic' => ['CZ6508000000192000145399'],
            'Slovakia' => ['SK3112000000198742637541'],
            'Germany' => ['DE89370400440532013000'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIbans(): array
    {
        return [
            'wrong checksum' => ['CZ6508000000192000145398'],
            'invalid syntax' => ['CZXX08000000192000145399'],
            'too short' => ['CZ650800'],
            'non alphanumeric' => ['CZ65-0800-0000-1920'],
        ];
    }
}
