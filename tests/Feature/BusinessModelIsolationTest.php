<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\BusinessContext\Exceptions\InvalidBusinessConnection;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Models\TestBusinessRecord;
use Tests\TestCase;

class BusinessModelIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSafeTestDatabases();

        foreach (BusinessConnection::cases() as $connection) {
            $schema = Schema::connection($connection->connectionName());
            $schema->dropIfExists('business_model_test_records');
            $schema->create('business_model_test_records', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->ensureSafeTestDatabases();

            foreach (BusinessConnection::cases() as $connection) {
                Schema::connection($connection->connectionName())
                    ->dropIfExists('business_model_test_records');
            }

            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_missing_context_fails_before_querying_central_database(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        try {
            TestBusinessRecord::query()->count();
            $this->fail('Business model bez contextu měl selhat.');
        } catch (MissingBusinessContext) {
            $this->assertSame([], DB::connection('central')->getQueryLog());
        }
    }

    public function test_business_1_context_writes_only_to_business_1(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);

        $record = TestBusinessRecord::query()->create(['name' => 'Pouze business 1']);

        $this->assertSame('business_1', $record->getConnectionName());
        $this->assertSame('central', config('database.default'));
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame(1, DB::connection('business_1')->table($record->getTable())->count());
        $this->assertSame(0, DB::connection('business_2')->table($record->getTable())->count());
        $this->assertFalse(Schema::connection('central')->hasTable($record->getTable()));
    }

    public function test_business_2_context_writes_only_to_business_2(): void
    {
        $this->activateBusiness(BusinessConnection::Business2);

        $record = TestBusinessRecord::query()->create(['name' => 'Pouze business 2']);

        $this->assertSame('business_2', $record->getConnectionName());
        $this->assertSame('central', config('database.default'));
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame(0, DB::connection('business_1')->table($record->getTable())->count());
        $this->assertSame(1, DB::connection('business_2')->table($record->getTable())->count());
        $this->assertFalse(Schema::connection('central')->hasTable($record->getTable()));
    }

    public function test_records_are_physically_isolated_between_business_databases(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        TestBusinessRecord::query()->create(['name' => 'Záznam prvního subjektu']);

        $this->activateBusiness(BusinessConnection::Business2);
        $this->assertFalse(
            TestBusinessRecord::query()->where('name', 'Záznam prvního subjektu')->exists(),
        );
        TestBusinessRecord::query()->create(['name' => 'Záznam druhého subjektu']);

        $this->activateBusiness(BusinessConnection::Business1);
        $this->assertFalse(
            TestBusinessRecord::query()->where('name', 'Záznam druhého subjektu')->exists(),
        );
        $this->assertSame(1, TestBusinessRecord::query()->count());

        $this->assertFalse(Schema::connection('central')->hasTable('business_model_test_records'));
    }

    #[DataProvider('invalidConnections')]
    public function test_resolver_rejects_invalid_connections(string $connectionName): void
    {
        $context = Mockery::mock(ActiveBusinessContext::class);
        $context->shouldReceive('connectionName')->once()->andReturn($connectionName);

        $this->expectException(InvalidBusinessConnection::class);

        (new BusinessConnectionResolver($context))->resolve();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConnections(): array
    {
        return [
            'central' => ['central'],
            'mysql' => ['mysql'],
            'sqlite' => ['sqlite'],
            'unknown business' => ['business_3'],
            'empty value' => [''],
            'forged string' => ['business_1;central'],
        ];
    }

    public function test_query_parameter_cannot_change_active_connection(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness(BusinessConnection::Business1);
        $user->businesses()->attach($business, ['role' => 'admin']);

        DB::connection('business_1')->table('business_model_test_records')->insert([
            'name' => 'Správný subjekt',
        ]);

        Route::get('/_tests/business-connection', function (): array {
            $model = new TestBusinessRecord;

            return [
                'connection' => $model->getConnectionName(),
                'records' => $model->newQuery()->count(),
            ];
        })->middleware(['web', 'auth', 'business.context', 'business.required']);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->get('/_tests/business-connection?connection=business_2')
            ->assertOk()
            ->assertJson([
                'connection' => 'business_1',
                'records' => 1,
            ]);

        $this->assertSame(0, DB::connection('business_2')
            ->table('business_model_test_records')
            ->count());
    }

    public function test_model_rejects_manual_connection_override(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);

        $this->expectException(InvalidBusinessConnection::class);

        (new TestBusinessRecord)->setConnection('business_2');
    }

    private function activateBusiness(BusinessConnection $connection): Business
    {
        $business = $this->createBusiness($connection);
        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    private function createBusiness(BusinessConnection $connection): Business
    {
        return Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => 'Testovací subjekt '.$connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1
                ? '12345678'
                : '87654321',
            'short_label' => $connection->connectionName(),
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(),
            'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);
    }
}
