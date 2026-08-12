<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\VatRate;
use App\Models\User;
use App\Services\Business\VatRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class VatRatesHttpTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    public function test_guest_and_user_without_active_business_cannot_open_module(): void
    {
        $this->get(route('vat-rates.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('vat-rates.index'))->assertForbidden();
    }

    public function test_viewer_reads_but_cannot_mutate_and_sees_non_payer_warning(): void
    {
        [$viewer, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $rate = app(VatRateService::class)->create($this->payload(['tax_type' => 'out_of_scope', 'percentage' => null]));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($viewer)->withSession($this->businessSession($business));
        $this->get(route('vat-rates.index'))->assertOk()->assertSee('Sazby DPH')->assertSee('neplátce DPH')->assertDontSee('Nová sazba');
        $this->get(route('vat-rates.show', $rate->uuid))->assertOk()->assertSee('Bez výpočtu sazby')->assertDontSee('Upravit');
        $this->post(route('vat-rates.store'), $this->payload())->assertForbidden();
        $this->patch(route('vat-rates.archive', $rate->uuid))->assertForbidden();
    }

    public function test_admin_crud_normalizes_decimal_and_rejects_technical_fields(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $fakeUuid = (string) Str::uuid();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $this->post(route('vat-rates.store').'?connection=business_2', $this->payload([
            'name' => '  Snížená sazba  ', 'code' => ' reduced_12 ', 'tax_type' => 'reduced',
            'percentage' => '12,5', 'uuid' => $fakeUuid,
        ]))->assertSessionHasErrors(['uuid', 'connection']);

        $this->post(route('vat-rates.store'), $this->payload([
            'name' => '  Snížená sazba  ', 'code' => ' reduced_12 ', 'tax_type' => 'reduced',
            'percentage' => '12,5',
        ]))->assertSessionHasNoErrors();

        $row = DB::connection('business_1')->table('vat_rates')->where('code', 'REDUCED_12')->first();
        $this->assertSame('Snížená sazba', $row->name);
        $this->assertSame('REDUCED_12', $row->code);
        $this->assertSame('12.5000', $row->percentage);
        $this->assertSame(1, DB::connection('business_2')->table('vat_rates')->count());
        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame($admin->email, DB::connection('business_1')->table('audit_logs')
            ->where('event', 'vat_rate.created')->value('actor_email'));

        $this->put(route('vat-rates.update', $row->uuid), $this->payload([
            'name' => 'Upravená sazba', 'code' => 'REDUCED_12', 'tax_type' => 'reduced', 'percentage' => '12.5',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Upravená sazba', DB::connection('business_1')->table('vat_rates')->where('uuid', $row->uuid)->value('name'));
    }

    public function test_foreign_uuid_is_404_and_filters_pagination_and_sort_are_safe(): void
    {
        [$admin, $first] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $second = $this->business(BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($second);
        $foreign = app(VatRateService::class)->create($this->payload());
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->businessSession($first));
        $this->get(route('vat-rates.show', $foreign->uuid))->assertNotFound();
        $this->put(route('vat-rates.update', $foreign->uuid), $this->payload())->assertNotFound();
        $this->get(route('vat-rates.index', ['status' => 'invalid', 'sort' => 'DROP TABLE']))->assertSessionHasErrors(['status', 'sort']);
    }

    public function test_admin_default_lifecycle_archive_and_routes_are_protected(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $rate = app(VatRateService::class)->create($this->payload(['tax_type' => 'out_of_scope', 'percentage' => null]));
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $this->patch(route('vat-rates.set-default', $rate->uuid))->assertSessionHasNoErrors();
        $this->patch(route('vat-rates.deactivate', $rate->uuid))->assertSessionHasNoErrors();
        $this->patch(route('vat-rates.activate', $rate->uuid))->assertSessionHasNoErrors();
        $this->patch(route('vat-rates.archive', $rate->uuid))->assertRedirect(route('vat-rates.index'));
        $this->get(route('vat-rates.show', $rate->uuid))->assertOk()->assertSee('Archivovaná')->assertDontSee('Upravit');
        $this->get(route('vat-rates.edit', $rate->uuid))->assertNotFound();

        foreach (['vat-rates.store', 'vat-rates.update', 'vat-rates.set-default', 'vat-rates.deactivate', 'vat-rates.activate', 'vat-rates.archive'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains('business.context', $middleware);
            $this->assertContains('business.required', $middleware);
        }
    }

    public function test_system_non_payer_is_read_only_and_cannot_be_submitted_by_admin(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $system = VatRate::query()->where('tax_type', 'non_payer')->sole();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $this->get(route('vat-rates.show', $system->uuid))
            ->assertOk()
            ->assertSee('Neplátce DPH')
            ->assertDontSee(route('vat-rates.edit', $system->uuid), false)
            ->assertDontSee(route('vat-rates.deactivate', $system->uuid), false)
            ->assertDontSee(route('vat-rates.archive', $system->uuid), false);
        $this->get(route('vat-rates.edit', $system->uuid))->assertNotFound();

        $this->post(route('vat-rates.store'), $this->payload([
            'tax_type' => 'non_payer',
            'percentage' => null,
        ]))->assertSessionHasErrors('tax_type');
        $this->put(route('vat-rates.update', $system->uuid), $this->payload())->assertNotFound();
        $this->patch(route('vat-rates.set-default', $system->uuid))->assertSessionHasErrors('rate');
        $this->patch(route('vat-rates.deactivate', $system->uuid))->assertSessionHasErrors('rate');
        $this->patch(route('vat-rates.archive', $system->uuid))->assertSessionHasErrors('rate');

        $system = DB::connection('business_1')->table('vat_rates')->where('uuid', $system->uuid)->sole();
        $this->assertSame('non_payer', $system->tax_type);
        $this->assertSame(1, (int) $system->is_active);
        $this->assertNull($system->archived_at);
        $this->assertSame(0, DB::connection('business_1')->table('vat_rate_defaults')->count());
    }

    /** @return array{User, Business} */
    private function userWithBusiness(string $role, BusinessConnection $connection): array
    {
        $user = User::factory()->create();
        $business = $this->business($connection);
        $user->businesses()->attach($business, ['role' => $role]);

        return [$user, $business];
    }

    private function business(BusinessConnection $connection): Business
    {
        return Business::query()->create([
            'uuid' => (string) Str::uuid(), 'display_name' => $connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function businessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Základní sazba', 'code' => 'STANDARD', 'tax_type' => 'standard',
            'percentage' => '21', 'valid_from' => '2026-01-01', 'valid_to' => null,
            'is_active' => '1', 'sort_order' => 10,
        ], $overrides);
    }
}
