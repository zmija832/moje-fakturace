<?php

namespace Tests\Concerns;

trait BuildsBusinessProcessEnvironment
{
    /** @return array<string, string> */
    protected function businessChildProcessEnvironment(): array
    {
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'central',
            'SESSION_DRIVER' => 'array',
        ];

        foreach ([
            'central' => 'CENTRAL',
            'business_1' => 'BUSINESS_1',
            'business_2' => 'BUSINESS_2',
        ] as $connection => $environmentSuffix) {
            foreach ([
                'host' => 'HOST',
                'port' => 'PORT',
                'database' => 'DATABASE',
                'username' => 'USERNAME',
                'password' => 'PASSWORD',
            ] as $configKey => $environmentKey) {
                $environment["DB_{$environmentSuffix}_{$environmentKey}"] =
                    (string) config("database.connections.{$connection}.{$configKey}");
            }
        }

        return $environment;
    }
}
