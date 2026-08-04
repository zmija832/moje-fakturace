<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\Exceptions\InvoiceDeliveryIdempotencyConflict;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\InvoicePdfGenerator;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $connectionName, $businessUuid, $invoiceUuid, $correlationUuid, $barrier] = $argv + array_fill(0, 6, null);

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
    'uuid' => (string) $businessUuid,
    'display_name' => 'Souběžný PDF test',
    'registration_number' => '12345678',
    'connection_name' => $connection->connectionName(),
    'is_active' => true,
]);
$app->make(ActiveBusinessContext::class)->set($business);

try {
    $app->make(InvoicePdfGenerator::class)->generate(
        (string) $invoiceUuid,
        (string) $correlationUuid,
    );
    exit(0);
} catch (InvoiceDeliveryIdempotencyConflict) {
    exit(20);
}
