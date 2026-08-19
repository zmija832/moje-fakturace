<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Services\Business\RecurringInvoiceRunner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $connectionName,$businessUuid,$templateUuid,$barrier] = $argv + array_fill(0, 5, null);
if (! $app->environment('testing')) {
    exit(10);
} $connection = BusinessConnection::fromConfiguredValue((string) $connectionName);
$database = config('database.connections.'.$connection->connectionName().'.database');
if (! is_string($database) || preg_match('/(?:^|[_-])test(?:[_-]|$)/i', $database) !== 1) {
    exit(11);
}
$deadline = microtime(true) + 10;
while (! is_file((string) $barrier)) {
    if (microtime(true) >= $deadline) {
        exit(12);
    }usleep(10000);
} $business = new Business;
$business->forceFill(['uuid' => $businessUuid, 'display_name' => 'Concurrency', 'registration_number' => '12345678', 'connection_name' => $connection->connectionName(), 'is_active' => true]);
$context = $app->make(ActiveBusinessContext::class);
$context->set($business);
try {
    $template = RecurringInvoiceTemplate::query()->where('uuid', $templateUuid)->firstOrFail();
    $app->make(RecurringInvoiceRunner::class)->run($template);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.PHP_EOL.$e->getMessage().PHP_EOL);
    exit(20);
} finally {
    $context->clear();
}
