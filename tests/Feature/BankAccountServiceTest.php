<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\BankAccountDefault;
use App\Services\Business\BankAccountService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class BankAccountServiceTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
    use InteractsWithBusinessDatabases;

    protected array $businessDatabaseTransactionExclusions = [
        'test_concurrent_default_changes_leave_exactly_one_default_per_currency',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        try {
            $this->ensureSafeTestDatabases();

            foreach (BusinessConnection::cases() as $connection) {
                if (Schema::connection($connection->connectionName())->hasTable('bank_account_defaults')) {
                    DB::connection($connection->connectionName())->table('bank_account_defaults')->delete();
                    DB::connection($connection->connectionName())->table('bank_accounts')->delete();
                }
            }

            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_models_fail_closed_without_context_before_any_central_query(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        foreach ([BankAccount::class, BankAccountDefault::class] as $model) {
            try {
                $model::query()->count();
                $this->fail("{$model} bez contextu měl selhat.");
            } catch (MissingBusinessContext) {
                $this->assertSame([], DB::connection('central')->getQueryLog());
            }
        }
    }

    public function test_accounts_are_physically_isolated_and_default_connection_stays_central(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->service()->create($this->attributes(['name' => 'První účet']));
        $this->assertSame('business_1', $first->getConnectionName());

        $this->activateBusiness(BusinessConnection::Business2);
        $second = $this->service()->create($this->attributes(['name' => 'Druhý účet']));

        $this->assertSame('business_2', $second->getConnectionName());
        $this->assertSame('První účet', DB::connection('business_1')->table('bank_accounts')->value('name'));
        $this->assertSame('Druhý účet', DB::connection('business_2')->table('bank_accounts')->value('name'));
        $this->assertFalse(Schema::connection('central')->hasTable('bank_accounts'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_uuid_is_generated_server_side_and_technical_fields_are_not_mass_assignable(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $fakeUuid = (string) Str::uuid();

        $account = $this->service()->create($this->attributes([
            'uuid' => $fakeUuid,
            'archived_at' => now(),
            'connection' => 'business_2',
            'connection_name' => 'business_2',
        ]));

        $this->assertNotSame($fakeUuid, $account->uuid);
        $this->assertTrue(Str::isUuid($account->uuid));
        $this->assertNull($account->archived_at);
        $this->assertSame('business_1', $account->getConnectionName());
    }

    public function test_service_normalizes_identifiers_and_preserves_leading_zeroes(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);

        $account = $this->service()->create($this->attributes([
            'domestic_prefix' => ' 019 ',
            'domestic_account_number' => ' 000123 456 ',
            'bank_code' => ' 0800 ',
            'iban' => ' cz65 0800 0000 1920 0014 5399 ',
            'bic' => ' giba cz px ',
        ]));

        $this->assertSame('019', $account->domestic_prefix);
        $this->assertSame('000123456', $account->domestic_account_number);
        $this->assertSame('0800', $account->bank_code);
        $this->assertSame('CZ6508000000192000145399', $account->iban);
        $this->assertSame('GIBACZPX', $account->bic);
        $this->assertSame('019-000123456/0800', $account->domesticDisplay());
    }

    public function test_same_uuid_and_id_in_both_databases_do_not_cross_business_boundary(): void
    {
        $sharedUuid = (string) Str::uuid();

        $this->activateBusiness(BusinessConnection::Business1);
        $first = new BankAccount;
        $first->forceFill(['uuid' => $sharedUuid]);
        $first->fill($this->attributes(['name' => 'První databáze']));
        $first->save();

        $this->activateBusiness(BusinessConnection::Business2);
        $second = new BankAccount;
        $second->forceFill(['uuid' => $sharedUuid]);
        $second->fill($this->attributes(['name' => 'Druhá databáze']));
        $second->save();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Druhá databáze', BankAccount::query()->where('uuid', $sharedUuid)->value('name'));

        $this->activateBusiness(BusinessConnection::Business1);
        $this->assertSame('První databáze', BankAccount::query()->where('uuid', $sharedUuid)->value('name'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_database_rejects_account_without_domestic_number_or_iban(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);

        $this->expectException(QueryException::class);

        $this->service()->create($this->attributes([
            'domestic_account_number' => null,
            'bank_code' => null,
            'iban' => null,
        ]));
    }

    public function test_default_account_is_atomically_replaced_and_unique_per_currency(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->service()->create($this->attributes(['name' => 'První CZK']));
        $second = $this->service()->create($this->attributes([
            'name' => 'Druhý CZK',
            'domestic_account_number' => '987654321',
        ]));
        $euro = $this->service()->create($this->attributes([
            'name' => 'EUR účet',
            'currency' => 'EUR',
            'iban' => 'DE89370400440532013000',
        ]));

        $this->service()->setDefault($first->uuid);
        $this->service()->setDefault($second->uuid);
        $this->service()->setDefault($euro->uuid);

        $this->assertSame(2, BankAccountDefault::query()->count());
        $this->assertSame($second->id, BankAccountDefault::query()->find('CZK')->bank_account_id);
        $this->assertSame($euro->id, BankAccountDefault::query()->find('EUR')->bank_account_id);
    }

    public function test_database_constraints_reject_duplicate_default_and_currency_mismatch(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $czk = $this->service()->create($this->attributes());
        $eur = $this->service()->create($this->attributes([
            'currency' => 'EUR',
            'iban' => 'DE89370400440532013000',
        ]));

        BankAccountDefault::query()->create([
            'currency' => 'CZK',
            'bank_account_id' => $czk->id,
        ]);

        try {
            BankAccountDefault::query()->create([
                'currency' => 'CZK',
                'bank_account_id' => $eur->id,
            ]);
            $this->fail('Primární klíč měl odmítnout druhý výchozí účet pro CZK.');
        } catch (QueryException) {
            $this->assertSame(1, BankAccountDefault::query()->count());
        }

        $this->expectException(QueryException::class);
        BankAccountDefault::query()->create([
            'currency' => 'EUR',
            'bank_account_id' => $czk->id,
        ]);
    }

    public function test_inactive_or_archived_account_cannot_be_default(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $inactive = $this->service()->create($this->attributes(['is_active' => false]));

        try {
            $this->service()->setDefault($inactive->uuid);
            $this->fail('Neaktivní účet neměl být možné nastavit jako výchozí.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account', $exception->errors());
        }

        $this->service()->archive($inactive->uuid);

        $this->expectException(ValidationException::class);
        $this->service()->setDefault($inactive->uuid);
    }

    public function test_deactivation_and_archiving_remove_default_without_deleting_history(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $account = $this->service()->create($this->attributes());
        $this->service()->setDefault($account->uuid);

        $deactivated = $this->service()->deactivate($account->uuid);
        $this->assertFalse($deactivated->is_active);
        $this->assertSame(0, BankAccountDefault::query()->count());

        $reactivated = $this->service()->activate($account->uuid);
        $this->assertTrue($reactivated->is_active);
        $this->service()->setDefault($account->uuid);

        $archived = $this->service()->archive($account->uuid);
        $this->assertFalse($archived->is_active);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame(0, BankAccountDefault::query()->count());
        $this->assertSame(1, BankAccount::query()->count());

        try {
            $this->service()->archive($account->uuid);
            $this->fail('Opakovaná archivace neměla přepsat historický čas archivace.');
        } catch (ModelNotFoundException) {
            $this->assertSame(
                $archived->archived_at->toDateTimeString(),
                $account->fresh()->archived_at->toDateTimeString(),
            );
        }

        $this->expectException(ValidationException::class);
        $this->service()->activate($account->uuid);
    }

    public function test_currency_of_default_account_cannot_change_until_another_default_is_selected(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $account = $this->service()->create($this->attributes());
        $this->service()->setDefault($account->uuid);

        try {
            $this->service()->update($account->uuid, $this->attributes(['currency' => 'EUR']));
            $this->fail('Měna výchozího účtu neměla jít změnit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency', $exception->errors());
            $this->assertSame('CZK', $account->fresh()->currency);
        }
    }

    public function test_concurrent_default_changes_leave_exactly_one_default_per_currency(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->service()->create($this->attributes(['name' => 'Souběžný první']));
        $second = $this->service()->create($this->attributes([
            'name' => 'Souběžný druhý',
            'domestic_account_number' => '987654321',
        ]));

        $barrier = storage_path('framework/testing/bank-default-'.Str::uuid());
        $processes = [
            new Process(
                [PHP_BINARY, base_path('tests/Support/set-bank-account-default.php'), 'business_1', $first->uuid, $barrier],
                base_path(),
                $this->businessChildProcessEnvironment(),
            ),
            new Process(
                [PHP_BINARY, base_path('tests/Support/set-bank-account-default.php'), 'business_1', $second->uuid, $barrier],
                base_path(),
                $this->businessChildProcessEnvironment(),
            ),
        ];

        try {
            foreach ($processes as $process) {
                $process->setTimeout(20);
                $process->start();
            }

            file_put_contents($barrier, 'start');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    $process->getErrorOutput().$process->getOutput(),
                );
            }
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        $this->assertSame(1, BankAccountDefault::query()->where('currency', 'CZK')->count());
        $this->assertContains(
            BankAccountDefault::query()->findOrFail('CZK')->bank_account_id,
            [$first->id, $second->id],
        );
    }

    private function activateBusiness(BusinessConnection $connection): Business
    {
        $business = Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => 'Subjekt '.$connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(),
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(),
            'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);

        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    private function service(): BankAccountService
    {
        return app(BankAccountService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Provozní účet',
            'domestic_prefix' => null,
            'domestic_account_number' => '123456789',
            'bank_code' => '0800',
            'iban' => 'CZ6508000000192000145399',
            'bic' => 'GIBACZPX',
            'currency' => 'CZK',
            'is_active' => true,
            'sort_order' => 10,
            'note' => 'Testovací poznámka',
        ], $overrides);
    }
}
