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
        if ($this->usesBusinessDatabaseTransactions()) {
            return;
        }

        $this->migrateFreshBusinessTestDatabases();
        $this->markBusinessTestDatabasesDirty();
    }

    protected function resetBusinessTestMigrations(): void
    {
        $this->ensureSafeTestDatabases();
        $defaultConnection = DB::getDefaultConnection();

        foreach (BusinessConnection::cases() as $connection) {
            $exitCode = Artisan::call('db:wipe', [
                '--database' => $connection->connectionName(),
                '--drop-views' => true,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Vyčištění testovací databáze {$connection->connectionName()} selhalo.",
                );
            }

            $exitCode = Artisan::call('migrate:install', [
                '--database' => $connection->connectionName(),
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Vytvoření migration repository v testovací databázi {$connection->connectionName()} selhalo.",
                );
            }
        }

        if (DB::getDefaultConnection() !== $defaultConnection) {
            throw new RuntimeException('Reset business testovacích migrací změnil default connection.');
        }

        $this->markBusinessTestDatabasesDirty();
    }
}
