<?php

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\Exceptions\InvoiceIssueIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssueVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotDraft;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Services\Business\InvoiceIssuer;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $connectionName, $invoiceUuid, $version, $correlationUuid, $barrier] = $argv + array_fill(0, 6, null);

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
    'uuid' => '00000000-0000-4000-8000-000000000001',
    'display_name' => 'Souběžný test',
    'registration_number' => '12345678',
    'connection_name' => $connection->connectionName(),
    'is_active' => true,
]);
$app->make(ActiveBusinessContext::class)->set($business);

try {
    $app->make(InvoiceIssuer::class)->issue(
        (string) $invoiceUuid,
        (int) $version,
        (string) $correlationUuid,
    );
    exit(0);
} catch (InvoiceNotDraft|InvoiceIssueVersionConflict|InvoiceIssueIdempotencyConflict) {
    exit(20);
}
