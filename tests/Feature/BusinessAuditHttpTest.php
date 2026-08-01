<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class BusinessAuditHttpTest extends TestCase
{
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_guest_and_user_without_active_business_cannot_read_audit(): void
    {
        $this->get(route('business-audit.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('business-audit.index'))->assertForbidden();
    }

    public function test_admin_mutation_creates_audit_with_response_request_id_and_safe_html(): void
    {
        [$admin, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $response = $this->actingAs($admin)->withSession($this->businessSession($business))
            ->post(route('bank-accounts.store').'?connection=business_2', $this->bankPayload([
                'connection' => 'business_2',
            ]));
        $response->assertSessionHasNoErrors();
        $requestId = $response->headers->get('X-Request-ID');

        $this->assertTrue(Str::isUuid($requestId));
        $audit = DB::connection('business_1')->table('audit_logs')->where('request_id', $requestId)->first();
        $this->assertNotNull($audit);
        $this->assertSame('bank_account.created', $audit->event);
        $this->assertSame('central-user:'.$admin->id, $audit->actor_user_uuid);
        $this->assertSame(0, DB::connection('business_2')->table('audit_logs')->count());

        $this->get(route('business-audit.index', ['event' => 'bank_account.created', 'actor' => $admin->name]))
            ->assertOk()->assertSee('Vytvořen bankovní účet')->assertSee($requestId);
        $this->get(route('business-audit.show', $audit->uuid))
            ->assertOk()->assertSee('Auditní záznam je neměnný')->assertSee('••••5399')
            ->assertDontSee('CZ6508000000192000145399')
            ->assertDontSee('123456789')
            ->assertDontSee('Citlivá bankovní poznámka');
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_viewer_can_read_list_and_detail_but_no_audit_mutation_route_exists(): void
    {
        [$viewer, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $audit = $this->rawAudit('client.updated');
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($viewer)->withSession($this->businessSession($business));
        $this->get(route('business-audit.index'))->assertOk()->assertSee('Upraven klient');
        $this->get(route('business-audit.show', $audit->uuid))->assertOk();

        $auditRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'business-audit.'));
        $this->assertSame(['GET', 'HEAD'], $auditRoutes->firstWhere('action.as', 'business-audit.index')->methods());
        $this->assertSame(['GET', 'HEAD'], $auditRoutes->firstWhere('action.as', 'business-audit.show')->methods());
        $this->assertCount(2, $auditRoutes);
    }

    public function test_foreign_uuid_is_not_found_and_filters_sort_and_pagination_are_tenant_safe(): void
    {
        [$admin, $businessOne] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $businessTwo = $this->createBusiness(BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessTwo);
        $foreign = $this->rawAudit('client.created');

        app(ActiveBusinessContext::class)->set($businessOne);
        for ($index = 0; $index < 52; $index++) {
            $this->rawAudit($index % 2 === 0 ? 'client.updated' : 'bank_account.updated', 'Uživatel '.$index);
        }
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->businessSession($businessOne));
        $this->get(route('business-audit.show', $foreign->uuid))->assertNotFound();
        $this->get(route('business-audit.index', [
            'event' => 'client.updated', 'auditable_type' => 'client',
            'actor' => 'Uživatel', 'sort' => 'DROP TABLE audit_logs', 'direction' => 'desc',
        ]))->assertOk()->assertSee('Upraven klient')->assertSee('Uživatel 50')->assertDontSee('Uživatel 51');
        $this->get(route('business-audit.index', ['event' => 'client.updated']))
            ->assertOk()->assertSee('event=client.updated', false);
    }

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
            'uuid' => (string) Str::uuid(), 'display_name' => 'Subjekt '.$connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);
    }

    private function rawAudit(string $event, string $actor = 'Testovací uživatel'): AuditLog
    {
        $audit = new AuditLog;
        $audit->forceFill([
            'event' => $event, 'actor_name' => $actor,
            'auditable_type' => str_starts_with($event, 'bank_account') ? 'bank_account' : 'client',
            'auditable_uuid' => (string) Str::uuid(), 'occurred_at' => now(),
        ])->save();

        return $audit;
    }

    private function businessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    /** @param array<string, mixed> $overrides */
    private function bankPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Auditní účet', 'domestic_prefix' => null,
            'domestic_account_number' => '123456789', 'bank_code' => '0800',
            'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX',
            'currency' => 'CZK', 'is_active' => '1', 'sort_order' => 10,
            'note' => 'Citlivá bankovní poznámka',
        ], $overrides);
    }
}
