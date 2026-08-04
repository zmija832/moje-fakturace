<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\InvoicePaymentService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $connectionName, $businessUuid, $invoiceUuid, $correlationUuid, $amount, $barrier] = $argv + array_fill(0, 7, null);

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
    'uuid' => (string) $businessUuid, 'display_name' => 'Souběžný test plateb',
    'registration_number' => '12345678', 'connection_name' => $connection->connectionName(), 'is_active' => true,
]);
$context = $app->make(ActiveBusinessContext::class);
$context->set($business);

try {
    $app->make(InvoicePaymentService::class)->record((string) $invoiceUuid, (string) $correlationUuid, [
        'amount' => (string) $amount, 'currency' => 'CZK', 'paid_on' => '2026-08-04', 'payment_method' => 'bank_transfer',
    ]);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.PHP_EOL);
    exit(20);
} finally {
    $context->clear();
}
