<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\InvoiceDeletionService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $connectionName, $businessUuid, $invoiceUuid, $barrier] = $argv + array_fill(0, 5, null);

if (! $app->environment('testing')) {
    exit(10);
}
$connection = BusinessConnection::fromConfiguredValue((string) $connectionName);
$database = config('database.connections.'.$connection->connectionName().'.database');
if (! is_string($database) || preg_match('/(?:^|[_-])test(?:[_-]|$)/i', $database) !== 1) {
    exit(11);
}
$deadline = microtime(true) + 10;
while (! is_file((string) $barrier)) {
    if (microtime(true) >= $deadline) {
        exit(12);
    }
    usleep(10_000);
}
$business = new Business;
$business->forceFill([
    'uuid' => (string) $businessUuid, 'display_name' => 'Souběžný test mazání',
    'registration_number' => '12345678', 'connection_name' => $connection->connectionName(), 'is_active' => true,
]);
$context = $app->make(ActiveBusinessContext::class);
$context->set($business);

try {
    $app->make(InvoiceDeletionService::class)->delete((string) $invoiceUuid);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.PHP_EOL.$exception->getMessage().PHP_EOL);
    exit(20);
} finally {
    $context->clear();
}
