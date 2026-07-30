<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\EnsuresSafeTestDatabases;

abstract class TestCase extends BaseTestCase
{
    use EnsuresSafeTestDatabases;
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseWithoutSafetyChecks;
    }

    protected $connectionsToTransact = ['central'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function refreshDatabase(): void
    {
        $this->ensureSafeTestDatabases();
        $this->refreshDatabaseWithoutSafetyChecks();
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
        ];
    }
}
