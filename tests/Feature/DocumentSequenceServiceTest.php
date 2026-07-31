<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\DocumentNumberAllocation;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use App\Services\Business\DocumentNumberAllocator;
use App\Services\Business\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class DocumentSequenceServiceTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
    use InteractsWithBusinessDatabases;

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
                $name = $connection->connectionName();

                if (Schema::connection($name)->hasTable('document_number_allocations')) {
                    DB::connection($name)->table('document_number_allocations')->delete();
                    DB::connection($name)->table('document_sequence_defaults')->delete();
                    DB::connection($name)->table('document_sequences')->delete();
                }
            }

            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_models_fail_closed_without_context_before_central_sql(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        foreach ([DocumentSequence::class, DocumentSequenceDefault::class, DocumentNumberAllocation::class] as $model) {
            try {
                $model::query()->count();
                $this->fail("{$model} bez Business Contextu měl selhat.");
            } catch (MissingBusinessContext) {
                $this->assertSame([], DB::connection('central')->getQueryLog());
            }
        }
    }

    public function test_sequences_and_allocations_are_physically_isolated(): void
    {
        $sharedUuid = (string) Str::uuid();

        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->sequenceService()->create($this->attributes(['name' => 'První databáze']));
        $first->forceFill(['uuid' => $sharedUuid])->save();
        $firstAllocation = $this->allocator()->allocate($sharedUuid, CarbonImmutable::parse('2026-03-10'));

        $this->activateBusiness(BusinessConnection::Business2);
        $second = $this->sequenceService()->create($this->attributes(['name' => 'Druhá databáze']));
        $second->forceFill(['uuid' => $sharedUuid])->save();
        $secondAllocation = $this->allocator()->allocate($sharedUuid, CarbonImmutable::parse('2026-03-10'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($firstAllocation->formatted_number, $secondAllocation->formatted_number);
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->assertSame(1, DB::connection('business_2')->table('document_number_allocations')->count());
        $this->assertFalse(Schema::connection('central')->hasTable('document_sequences'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_uuid_is_server_generated_and_technical_fields_are_not_assignable(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $fakeUuid = (string) Str::uuid();
        $sequence = $this->sequenceService()->create($this->attributes([
            'uuid' => $fakeUuid,
            'next_number' => 999,
            'current_period' => '1999',
            'archived_at' => now(),
            'connection' => 'business_2',
        ]));

        $this->assertNotSame($fakeUuid, $sequence->uuid);
        $this->assertTrue(Str::isUuid($sequence->uuid));
        $this->assertSame(1, $sequence->next_number);
        $this->assertNull($sequence->current_period);
        $this->assertNull($sequence->archived_at);
        $this->assertNotContains('next_number', $sequence->getFillable());
        $this->assertNotContains('current_period', $sequence->getFillable());
        $this->assertSame([], (new DocumentNumberAllocation)->getFillable());
        $this->assertSame('business_1', $sequence->getConnectionName());
    }

    public function test_admin_configuration_service_updates_only_unused_format(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $sequence = $this->sequenceService()->create($this->attributes());
        $updated = $this->sequenceService()->update($sequence->uuid, $this->attributes([
            'name' => 'Upravená nepoužitá řada',
            'prefix' => 'NEW-',
            'start_number' => 25,
        ]));

        $this->assertSame('NEW-', $updated->prefix);
        $this->assertSame(25, $updated->start_number);
        $this->assertSame(25, $updated->next_number);

        $this->allocator()->allocate($sequence->uuid, CarbonImmutable::parse('2026-01-01'));

        try {
            $this->sequenceService()->update($sequence->uuid, $this->attributes([
                'name' => 'Povolená změna názvu',
                'prefix' => 'BLOCKED-',
                'start_number' => 50,
            ]));
            $this->fail('Použitou řadu nemělo být možné přeformátovat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sequence', $exception->errors());
        }

        $renamed = $this->sequenceService()->update($sequence->uuid, $this->attributes([
            'name' => 'Povolená změna názvu',
            'prefix' => 'NEW-',
            'start_number' => 25,
            'sort_order' => 20,
        ]));
        $this->assertSame('Povolená změna názvu', $renamed->name);
        $this->assertSame(20, $renamed->sort_order);
    }

    public function test_default_is_unique_per_type_and_type_mismatch_is_rejected_by_database(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->sequenceService()->create($this->attributes(['name' => 'První', 'prefix' => 'A-']));
        $second = $this->sequenceService()->create($this->attributes(['name' => 'Druhá', 'prefix' => 'B-']));
        $credit = $this->sequenceService()->create($this->attributes([
            'name' => 'Dobropisy',
            'document_type' => 'credit_note',
            'prefix' => 'D-',
        ]));

        $this->sequenceService()->setDefault($first->uuid);
        $this->sequenceService()->setDefault($second->uuid);
        $this->sequenceService()->setDefault($credit->uuid);

        $this->assertSame(2, DocumentSequenceDefault::query()->count());
        $this->assertSame($second->id, DocumentSequenceDefault::query()->find('issued_invoice')->document_sequence_id);

        $this->expectException(QueryException::class);
        DocumentSequenceDefault::query()->create([
            'document_type' => 'advance_invoice',
            'document_sequence_id' => $credit->id,
        ]);
    }

    public function test_inactive_and_archived_sequence_cannot_be_default_or_reactivated(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $sequence = $this->sequenceService()->create($this->attributes());
        $this->sequenceService()->setDefault($sequence->uuid);
        $this->sequenceService()->deactivate($sequence->uuid);

        $this->assertSame(0, DocumentSequenceDefault::query()->count());

        try {
            $this->sequenceService()->setDefault($sequence->uuid);
            $this->fail('Neaktivní řada neměla být výchozí.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sequence', $exception->errors());
        }

        $this->sequenceService()->activate($sequence->uuid);
        $this->sequenceService()->setDefault($sequence->uuid);
        $archived = $this->sequenceService()->archive($sequence->uuid);
        $archivedAt = $archived->archived_at->toDateTimeString();

        $this->assertFalse($archived->is_active);
        $this->assertSame(0, DocumentSequenceDefault::query()->count());

        try {
            $this->sequenceService()->archive($sequence->uuid);
            $this->fail('Opakovaná archivace měla skončit nenalezením.');
        } catch (ModelNotFoundException) {
            $this->assertSame($archivedAt, $sequence->fresh()->archived_at->toDateTimeString());
        }

        $this->expectException(ValidationException::class);
        $this->sequenceService()->activate($sequence->uuid);
    }

    public function test_preview_is_read_only_and_formats_none_yy_and_yyyy(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $date = CarbonImmutable::parse('2026-06-15');
        $none = $this->sequenceService()->create($this->attributes([
            'prefix' => 'N-', 'year_format' => 'none', 'sequence_digits' => 4, 'start_number' => 7,
        ]));
        $yy = $this->sequenceService()->create($this->attributes([
            'prefix' => 'Y-', 'year_format' => 'yy', 'sequence_digits' => 4, 'start_number' => 7,
        ]));
        $yyyy = $this->sequenceService()->create($this->attributes([
            'prefix' => 'F-', 'year_format' => 'yyyy', 'sequence_digits' => 4, 'start_number' => 7,
        ]));

        $this->assertSame('N-0007', $this->sequenceService()->preview($none->uuid, $date));
        $this->assertSame('Y-260007', $this->sequenceService()->preview($yy->uuid, $date));
        $this->assertSame('F-20260007', $this->sequenceService()->preview($yyyy->uuid, $date));
        $this->assertSame(0, DocumentNumberAllocation::query()->count());
        $this->assertSame(7, $yyyy->fresh()->next_number);
        $this->assertNull($yyyy->fresh()->current_period);
    }

    public function test_allocation_increments_once_and_is_idempotent(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $sequence = $this->sequenceService()->create($this->attributes());
        $correlation = (string) Str::uuid();
        $date = CarbonImmutable::parse('2026-02-20');

        $first = $this->allocator()->allocate($sequence->uuid, $date, $correlation);
        $repeated = $this->allocator()->allocate($sequence->uuid, $date, $correlation);

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(1, $first->sequence_number);
        $this->assertSame('FV-202600001', $first->formatted_number);
        $this->assertSame('2026', $first->period);
        $this->assertSame(1, DocumentNumberAllocation::query()->count());
        $this->assertSame(2, $sequence->fresh()->next_number);
        $this->assertSame('2026', $sequence->fresh()->current_period);
    }

    public function test_same_correlation_for_another_sequence_is_rejected(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $first = $this->sequenceService()->create($this->attributes(['prefix' => 'A-']));
        $second = $this->sequenceService()->create($this->attributes(['prefix' => 'B-']));
        $correlation = (string) Str::uuid();
        $date = CarbonImmutable::parse('2026-01-01');
        $this->allocator()->allocate($first->uuid, $date, $correlation);

        try {
            $this->allocator()->allocate($second->uuid, $date, $correlation);
            $this->fail('Cizí idempotency klíč měl být odmítnut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correlation_uuid', $exception->errors());
            $this->assertSame(1, DocumentNumberAllocation::query()->count());
            $this->assertSame(1, $second->fresh()->next_number);
        }
    }

    public function test_never_and_yearly_periods_and_backdated_allocation_are_safe(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $yearly = $this->sequenceService()->create($this->attributes(['prefix' => 'Y-']));
        $never = $this->sequenceService()->create($this->attributes([
            'prefix' => 'N-', 'reset_period' => 'never', 'year_format' => 'none',
        ]));

        $a = $this->allocator()->allocate($yearly->uuid, CarbonImmutable::parse('2026-01-01'));
        $b = $this->allocator()->allocate($yearly->uuid, CarbonImmutable::parse('2026-12-31'));
        $c = $this->allocator()->allocate($yearly->uuid, CarbonImmutable::parse('2027-01-01'));
        $d = $this->allocator()->allocate($yearly->uuid, CarbonImmutable::parse('2026-06-01'));
        $n1 = $this->allocator()->allocate($never->uuid, CarbonImmutable::parse('2026-01-01'));
        $n2 = $this->allocator()->allocate($never->uuid, CarbonImmutable::parse('2030-01-01'));

        $this->assertSame([1, 2, 1, 3], [$a->sequence_number, $b->sequence_number, $c->sequence_number, $d->sequence_number]);
        $this->assertSame(['2026', '2026', '2027', '2026'], [$a->period, $b->period, $c->period, $d->period]);
        $this->assertSame([1, 2], [$n1->sequence_number, $n2->sequence_number]);
        $this->assertSame(['never', 'never'], [$n1->period, $n2->period]);
        $this->assertNull($never->fresh()->current_period);
    }

    public function test_failed_allocation_rolls_back_counter(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $date = CarbonImmutable::parse('2026-01-01');
        $first = $this->sequenceService()->create($this->attributes([
            'prefix' => 'FV-20', 'year_format' => 'yy',
        ]));
        $colliding = $this->sequenceService()->create($this->attributes([
            'prefix' => 'FV-', 'year_format' => 'yyyy',
        ]));
        $this->allocator()->allocate($first->uuid, $date);

        try {
            $this->allocator()->allocate($colliding->uuid, $date);
            $this->fail('Duplicitní formatted_number měla odmítnout databáze.');
        } catch (QueryException) {
            $this->assertSame(1, $colliding->fresh()->next_number);
            $this->assertNull($colliding->fresh()->current_period);
            $this->assertSame(1, DocumentNumberAllocation::query()->count());
        }
    }

    public function test_inactive_or_archived_sequence_cannot_allocate_and_allocations_are_immutable(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $inactive = $this->sequenceService()->create($this->attributes(['is_active' => false]));

        try {
            $this->allocator()->allocate($inactive->uuid, CarbonImmutable::parse('2026-01-01'));
            $this->fail('Neaktivní řada neměla alokovat číslo.');
        } catch (ValidationException) {
            $this->assertSame(0, DocumentNumberAllocation::query()->count());
        }

        $this->sequenceService()->activate($inactive->uuid);
        $allocation = $this->allocator()->allocate($inactive->uuid, CarbonImmutable::parse('2026-01-01'));
        $this->sequenceService()->archive($inactive->uuid);

        try {
            $this->allocator()->allocate($inactive->uuid, CarbonImmutable::parse('2026-01-02'));
            $this->fail('Archivovaná řada neměla alokovat číslo.');
        } catch (ValidationException) {
            $this->assertSame(1, DocumentNumberAllocation::query()->count());
        }

        try {
            $allocation->formatted_number = 'ZMENA';
            $allocation->save();
            $this->fail('Allocation neměla jít změnit.');
        } catch (LogicException) {
            $this->assertSame('FV-202600001', $allocation->fresh()->formatted_number);
        }

        $this->expectException(LogicException::class);
        $allocation->delete();
    }

    public function test_two_real_processes_allocate_unique_numbers_repeatedly(): void
    {
        $this->activateBusiness(BusinessConnection::Business1);
        $sequence = $this->sequenceService()->create($this->attributes(['prefix' => 'CON-']));

        for ($round = 0; $round < 2; $round++) {
            $barrier = storage_path('framework/testing/document-allocation-'.Str::uuid());
            $processes = [];

            for ($process = 0; $process < 2; $process++) {
                $processes[] = new Process(
                    [
                        PHP_BINARY,
                        base_path('tests/Support/allocate-document-number.php'),
                        'business_1',
                        $sequence->uuid,
                        '2026-07-31',
                        (string) Str::uuid(),
                        $barrier,
                    ],
                    base_path(),
                    $this->businessChildProcessEnvironment(),
                );
            }

            try {
                foreach ($processes as $child) {
                    $child->setTimeout(20);
                    $child->start();
                }

                file_put_contents($barrier, 'start');

                foreach ($processes as $child) {
                    $child->wait();
                    $this->assertTrue($child->isSuccessful(), $child->getErrorOutput().$child->getOutput());
                }
            } finally {
                if (is_file($barrier)) {
                    unlink($barrier);
                }
            }
        }

        $allocations = DocumentNumberAllocation::query()->orderBy('sequence_number')->get();
        $this->assertSame([1, 2, 3, 4], $allocations->pluck('sequence_number')->all());
        $this->assertCount(4, $allocations->pluck('formatted_number')->unique());
        $this->assertSame(5, $sequence->fresh()->next_number);
        $this->assertSame('2026', $sequence->fresh()->current_period);
        $this->assertSame(4, DocumentNumberAllocation::query()->count());
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

    private function sequenceService(): DocumentSequenceService
    {
        return app(DocumentSequenceService::class);
    }

    private function allocator(): DocumentNumberAllocator
    {
        return app(DocumentNumberAllocator::class);
    }

    /** @param array<string, mixed> $overrides */
    private function attributes(array $overrides = []): array
    {
        return array_replace([
            'document_type' => 'issued_invoice',
            'name' => 'Faktury',
            'prefix' => 'FV-',
            'suffix' => '',
            'year_format' => 'yyyy',
            'sequence_digits' => 5,
            'start_number' => 1,
            'reset_period' => 'yearly',
            'is_active' => true,
            'sort_order' => 10,
        ], $overrides);
    }
}
