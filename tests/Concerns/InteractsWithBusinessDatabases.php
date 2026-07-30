<?php

namespace Tests\Concerns;

use App\Enums\BusinessConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait InteractsWithBusinessDatabases
{
    protected function refreshBusinessTestDatabases(): void
    {
        $this->ensureSafeTestDatabases();
        $defaultConnection = DB::getDefaultConnection();

        foreach (BusinessConnection::cases() as $connection) {
            $exitCode = Artisan::call('migrate:fresh', [
                '--database' => $connection->connectionName(),
                '--path' => [database_path('migrations/business')],
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Obnova testovací databáze {$connection->connectionName()} selhala.",
                );
            }
        }

        if (DB::getDefaultConnection() !== $defaultConnection) {
            throw new RuntimeException('Obnova business testovacích databází změnila default connection.');
        }
    }

    protected function resetBusinessTestMigrations(): void
    {
        $this->refreshBusinessTestDatabases();
        $defaultConnection = DB::getDefaultConnection();

        foreach (BusinessConnection::cases() as $connection) {
            $exitCode = Artisan::call('migrate:reset', [
                '--database' => $connection->connectionName(),
                '--path' => [database_path('migrations/business')],
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Reset migrací testovací databáze {$connection->connectionName()} selhal.",
                );
            }
        }

        if (DB::getDefaultConnection() !== $defaultConnection) {
            throw new RuntimeException('Reset business testovacích migrací změnil default connection.');
        }
    }
}
