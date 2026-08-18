<?php

use App\Enums\BusinessConnection;

return [
    'allowed_connections' => BusinessConnection::values(),

    'session_key' => 'active_business_uuid',

    'invoice_test_purge_uuids' => array_values(array_filter(array_map(
        static fn (string $uuid): string => strtolower(trim($uuid)),
        explode(',', (string) env('INVOICE_TEST_PURGE_UUIDS', '')),
    ))),
];
