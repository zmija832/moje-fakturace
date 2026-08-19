<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\InvoicePaidNotificationService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $connectionName, $businessUuid, $payload, $barrier] = $argv + array_fill(0, 5, null);
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
    usleep(10000);
}
$values = json_decode(base64_decode((string) $payload, true) ?: '', true, flags: JSON_THROW_ON_ERROR);
$business = new Business;
$business->forceFill([
    'uuid' => $businessUuid,
    'display_name' => 'Concurrency',
    'registration_number' => '12345678',
    'connection_name' => $connection->connectionName(),
    'is_active' => true,
]);
$context = $app->make(ActiveBusinessContext::class);
$context->set($business);
config(['mail.default' => 'log']);
try {
    $app->make(InvoicePaidNotificationService::class)->handle(new InvoicePaymentEventSnapshot(...$values));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.PHP_EOL.$exception->getMessage().PHP_EOL);
    exit(20);
} finally {
    $context->clear();
}
