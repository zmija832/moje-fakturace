<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['central'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
