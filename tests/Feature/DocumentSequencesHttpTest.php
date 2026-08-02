<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\User;
use App\Services\Business\DocumentNumberAllocator;
use App\Services\Business\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class DocumentSequencesHttpTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        try {
            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_guest_is_redirected_and_user_without_business_is_forbidden(): void
    {
        $this->get(route('document-sequences.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('document-sequences.index'))
            ->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_mutate_and_unknown_role_cannot_mutate(): void
    {
        [$viewer, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $sequence = app(DocumentSequenceService::class)->create($this->validPayload());
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($viewer)->withSession($this->businessSession($business));
        $this->get(route('document-sequences.index'))
            ->assertOk()
            ->assertSee('Číselné řady')
            ->assertSee('Faktury 2026')
            ->assertDontSee('Nová číselná řada');
        $this->get(route('document-sequences.show', $sequence->uuid))->assertOk();
        $this->post(route('document-sequences.store'), $this->validPayload())->assertForbidden();
        $this->put(route('document-sequences.update', $sequence->uuid), $this->validPayload())->assertForbidden();
        $this->patch(route('document-sequences.archive', $sequence->uuid))->assertForbidden();

        [$unknown, $unknownBusiness] = $this->userWithBusiness('legacy-role', BusinessConnection::Business2);
        $this->actingAs($unknown)->withSession($this->businessSession($unknownBusiness));
        $this->post(route('document-sequences.store'), $this->validPayload())->assertForbidden();
    }

    public function test_admin_creates_and_updates_sequence_without_accepting_technical_fields_or_connection(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $fakeUuid = (string) Str::uuid();

        $this->actingAs($admin)->withSession($this->businessSession($business));
        $this->post(route('document-sequences.store').'?connection=business_2', $this->validPayload([
            'name' => '  Faktury hlavní  ',
            'connection' => 'business_2',
            'connection_name' => 'business_2',
            'uuid' => $fakeUuid,
            'next_number' => 999,
            'current_period' => '1999',
            'archived_at' => now()->toDateTimeString(),
        ]))->assertSessionHasNoErrors();

        $row = DB::connection('business_1')->table('document_sequences')->first();
        $this->assertSame('Faktury hlavní', $row->name);
        $this->assertNotSame($fakeUuid, $row->uuid);
        $this->assertSame(1, (int) $row->next_number);
        $this->assertNull($row->current_period);
        $this->assertNull($row->archived_at);
        $this->assertSame(0, DB::connection('business_2')->table('document_sequences')->count());
        $this->assertSame('central', DB::getDefaultConnection());

        $this->put(route('document-sequences.update', $row->uuid), $this->validPayload([
            'name' => 'Upravená řada',
            'sort_order' => 25,
            'next_number' => 500,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('document_sequences', [
            'uuid' => $row->uuid,
            'name' => 'Upravená řada',
            'sort_order' => 25,
            'next_number' => 1,
        ], 'business_1');
    }

    public function test_admin_manages_default_lifecycle_and_archived_detail_is_read_only(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $sequence = app(DocumentSequenceService::class)->create($this->validPayload());
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->businessSession($business));
        $this->patch(route('document-sequences.set-default', $sequence->uuid))
            ->assertSessionHas('status', 'Výchozí číselná řada byla změněna.');
        $this->assertSame(1, DB::connection('business_1')->table('document_sequence_defaults')->count());

        $this->patch(route('document-sequences.deactivate', $sequence->uuid))->assertSessionHasNoErrors();
        $this->assertSame(0, DB::connection('business_1')->table('document_sequence_defaults')->count());
        $this->patch(route('document-sequences.activate', $sequence->uuid))->assertSessionHasNoErrors();
        $this->patch(route('document-sequences.archive', $sequence->uuid))
            ->assertRedirect(route('document-sequences.index'));

        $archivedAt = DB::connection('business_1')->table('document_sequences')
            ->where('uuid', $sequence->uuid)->value('archived_at');
        $this->get(route('document-sequences.show', $sequence->uuid))
            ->assertOk()
            ->assertSee('pouze ke čtení')
            ->assertDontSee('Upravit');
        $this->get(route('document-sequences.edit', $sequence->uuid))->assertNotFound();
        $this->patch(route('document-sequences.archive', $sequence->uuid))->assertNotFound();
        $this->assertSame($archivedAt, DB::connection('business_1')->table('document_sequences')
            ->where('uuid', $sequence->uuid)->value('archived_at'));
    }

    public function test_used_sequence_cannot_be_reformatted_over_http(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $sequence = app(DocumentSequenceService::class)->create($this->validPayload());
        app(DocumentNumberAllocator::class)->allocate($sequence->uuid, CarbonImmutable::parse('2026-01-01'));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->businessSession($business));
        $this->put(route('document-sequences.update', $sequence->uuid), $this->validPayload([
            'prefix' => 'CHANGED-',
        ]))->assertSessionHasErrors('sequence');

        $this->assertSame('FV-', DB::connection('business_1')->table('document_sequences')
            ->where('uuid', $sequence->uuid)->value('prefix'));
    }

    public function test_uuid_from_other_business_is_not_visible_or_mutable(): void
    {
        [$admin, $businessOne] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $businessTwo = $this->createBusiness(BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessTwo);
        $foreign = app(DocumentSequenceService::class)->create($this->validPayload());
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->businessSession($businessOne));
        $this->get(route('document-sequences.show', $foreign->uuid))->assertNotFound();
        $this->get(route('document-sequences.edit', $foreign->uuid))->assertNotFound();
        $this->put(route('document-sequences.update', $foreign->uuid), $this->validPayload())->assertNotFound();
        $this->patch(route('document-sequences.set-default', $foreign->uuid))->assertNotFound();
        $this->patch(route('document-sequences.archive', $foreign->uuid))->assertNotFound();
        $this->assertSame(0, DB::connection('business_1')->table('document_sequences')->count());
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_rejects_invalid_configuration(string $field, mixed $value, string $error): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($admin)->withSession($this->businessSession($business))
            ->post(route('document-sequences.store'), $this->validPayload([$field => $value]))
            ->assertSessionHasErrors($error);
    }

    public function test_routes_have_required_middleware_csrf_and_no_public_allocation_endpoint(): void
    {
        foreach ([
            'document-sequences.store',
            'document-sequences.update',
            'document-sequences.set-default',
            'document-sequences.deactivate',
            'document-sequences.activate',
            'document-sequences.archive',
        ] as $routeName) {
            $middleware = app('router')->getRoutes()->getByName($routeName)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('business.context', $middleware);
            $this->assertContains('business.required', $middleware);
        }

        $allocationRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'alok'));
        $this->assertCount(0, $allocationRoutes);

        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $this->actingAs($admin)->withSession($this->businessSession($business))
            ->get(route('document-sequences.create'))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('Živý náhled');
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'invalid type' => ['document_type', 'invoice', 'document_type'],
            'empty name' => ['name', '', 'name'],
            'long prefix' => ['prefix', str_repeat('x', 65), 'prefix'],
            'invalid year' => ['year_format', 'YY', 'year_format'],
            'digits too low' => ['sequence_digits', 0, 'sequence_digits'],
            'digits too high' => ['sequence_digits', 13, 'sequence_digits'],
            'start below one' => ['start_number', 0, 'start_number'],
            'start does not fit' => ['start_number', 100000, 'start_number'],
            'invalid reset' => ['reset_period', 'monthly', 'reset_period'],
            'invalid active' => ['is_active', 'maybe', 'is_active'],
            'negative order' => ['sort_order', -1, 'sort_order'],
        ];
    }

    /** @return array{User, Business} */
    private function userWithBusiness(string $role, BusinessConnection $connection): array
    {
        $user = User::factory()->create();
        $business = $this->createBusiness($connection);
        $user->businesses()->attach($business, ['role' => $role]);

        return [$user, $business];
    }

    private function createBusiness(BusinessConnection $connection): Business
    {
        return Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => 'Subjekt '.$connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(),
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(),
            'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);
    }

    /** @return array<string, string> */
    private function businessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'document_type' => 'issued_invoice',
            'name' => 'Faktury 2026',
            'prefix' => 'FV-',
            'suffix' => '',
            'year_format' => 'yyyy',
            'sequence_digits' => 5,
            'start_number' => 1,
            'reset_period' => 'yearly',
            'is_active' => '1',
            'sort_order' => 10,
        ], $overrides);
    }
}
