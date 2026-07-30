<?php

namespace Tests\Concerns;

use RuntimeException;

trait EnsuresSafeTestDatabases
{
    protected function ensureSafeTestDatabases(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Destruktivní databázové testy lze spustit pouze v prostředí testing.');
        }

        $databases = [];

        foreach (['central', 'business_1', 'business_2'] as $connection) {
            $database = config("database.connections.{$connection}.database");

            if (! is_string($database) || trim($database) === '') {
                throw new RuntimeException("Testovací databáze pro připojení {$connection} není nastavená.");
            }

            $database = trim($database);

            if (preg_match('/(?:^|[_-])test(?:[_-]|$)/i', $database) !== 1) {
                throw new RuntimeException(
                    "Databáze {$database} nemá jednoznačný testovací marker.",
                );
            }

            if (preg_match('/(?:^|[_-])(?:local|prod|production)(?:[_-]|$)/i', $database) === 1) {
                throw new RuntimeException(
                    "Lokální nebo produkční databáze {$database} nesmí být použita v testech.",
                );
            }

            $databases[$connection] = mb_strtolower($database);
        }

        if (count(array_unique($databases)) !== count($databases)) {
            throw new RuntimeException(
                'Centrální a obě business testovací databáze musejí být navzájem rozdílné.',
            );
        }
    }
}
