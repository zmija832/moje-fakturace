<?php

namespace Tests\Concerns;

trait BuildsBusinessProcessEnvironment
{
    /** @param list<string> $arguments @return list<string> */
    protected function businessPhpCommand(string $script, array $arguments = []): array
    {
        $command = [PHP_BINARY, '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['mbstring', 'openssl', 'fileinfo', 'curl', 'intl', 'gd', 'pdo_mysql'] as $extension) {
            if (extension_loaded($extension)) {
                $command[] = '-d';
                $command[] = 'extension=php_'.$extension.'.dll';
            }
        }

        return [...$command, $script, ...$arguments];
    }

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
