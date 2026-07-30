<?php

use App\Enums\BusinessConnection;

return [
    'allowed_connections' => BusinessConnection::values(),

    'session_key' => 'active_business_uuid',
];
