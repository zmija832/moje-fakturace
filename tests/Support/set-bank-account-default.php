<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\BankAccountService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $connectionName, $uuid, $barrier] = $argv + [null, null, null, null];

if (! $app->environment('testing')) {
    fwrite(STDERR, "Pomocný proces smí běžet pouze v prostředí testing.\n");
    exit(10);
}

$connection = BusinessConnection::fromConfiguredValue((string) $connectionName);
$database = config("database.connections.{$connection->connectionName()}.database");

if (! is_string($database) || preg_match('/(?:^|[_-])test(?:[_-]|$)/i', $database) !== 1) {
    fwrite(STDERR, "Pomocný proces odmítl databázi bez testovacího markeru.\n");
    exit(11);
}

$deadline = microtime(true) + 10;

while (! is_file((string) $barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Čekání na synchronizační bariéru vypršelo.\n");
        exit(12);
    }

    usleep(10_000);
}

$business = new Business;
$business->forceFill([
    'uuid' => '00000000-0000-4000-8000-000000000001',
    'display_name' => 'Souběžný test',
    'registration_number' => '12345678',
    'connection_name' => $connection->connectionName(),
    'is_active' => true,
]);

$app->make(ActiveBusinessContext::class)->set($business);
$app->make(BankAccountService::class)->setDefault((string) $uuid);

exit(0);
