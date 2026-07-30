<?php

namespace App\Console\Commands;

use App\Enums\BusinessConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrateBusinessesCommand extends Command
{
    protected $signature = 'app:migrate-businesses
        {--business= : Migrovat pouze business_1 nebo business_2}
        {--force : Povolit spuštění bez potvrzení v produkci}';

    protected $description = 'Bezpečně spustí společné migrace nad povolenými business databázemi';

    public function handle(): int
    {
        $connections = $this->connectionsToMigrate();

        if ($connections === null) {
            return self::FAILURE;
        }

        if (! $this->confirmProductionRun()) {
            return self::FAILURE;
        }

        $defaultConnection = DB::getDefaultConnection();

        foreach ($connections as $connection) {
            $connectionName = $connection->connectionName();
            $this->components->info("Migrace business databáze: {$connectionName}");

            $arguments = [
                '--database' => $connectionName,
                '--path' => [database_path('migrations/business')],
                '--realpath' => true,
            ];

            if ($this->option('force')) {
                $arguments['--force'] = true;
            }

            try {
                $exitCode = $this->call('migrate', $arguments);
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error("Migrace databáze {$connectionName} selhala.");

                return self::FAILURE;
            }

            if ($exitCode !== self::SUCCESS) {
                $this->components->error("Migrace databáze {$connectionName} selhala.");

                return self::FAILURE;
            }

            if (DB::getDefaultConnection() !== $defaultConnection) {
                $this->components->error('Výchozí databázové připojení se během migrace neočekávaně změnilo.');

                return self::FAILURE;
            }
        }

        $this->components->info('Business migrace byly úspěšně dokončeny.');

        return self::SUCCESS;
    }

    /**
     * @return list<BusinessConnection>|null
     */
    private function connectionsToMigrate(): ?array
    {
        $requested = $this->option('business');

        if ($requested === null) {
            return BusinessConnection::cases();
        }

        $connection = BusinessConnection::tryFrom((string) $requested);

        if (! $connection || ! in_array($connection->value, config('business.allowed_connections'), true)) {
            $this->components->error('Povolené hodnoty --business jsou pouze business_1 a business_2.');

            return null;
        }

        return [$connection];
    }

    private function confirmProductionRun(): bool
    {
        if (! app()->isProduction() || $this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('V produkci použijte --force nebo potvrďte migraci interaktivně.');

            return false;
        }

        return $this->confirm(
            'Opravdu chcete spustit migrace nad vybranými produkčními business databázemi?',
        );
    }
}
