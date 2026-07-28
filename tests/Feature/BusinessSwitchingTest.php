<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessSwitchAudit;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessSwitchingTest extends TestCase
{
    public function test_user_sees_only_allowed_businesses(): void
    {
        $user = User::factory()->create();
        $allowed = $this->business('Povolená OSVČ', '12345678', 'business_1');
        $forbidden = $this->business('Cizí OSVČ', '87654321', 'business_2');
        $user->businesses()->attach($allowed, ['role' => 'admin']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($allowed->display_name)
            ->assertDontSee($forbidden->display_name);
    }

    public function test_user_can_switch_to_allowed_business_and_session_is_updated(): void
    {
        $user = User::factory()->create();
        $first = $this->business('První OSVČ', '12345678', 'business_1', 1);
        $second = $this->business('Druhá OSVČ', '87654321', 'business_2', 2);
        $user->businesses()->attach([
            $first->id => ['role' => 'admin'],
            $second->id => ['role' => 'admin'],
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->post(route('business.switch', $second->uuid))
            ->assertRedirect()
            ->assertSessionHas(config('business.session_key'), $second->uuid);

        $this->assertDatabaseHas(BusinessSwitchAudit::class, [
            'user_id' => $user->id,
            'to_business_id' => $second->id,
            'result' => 'success',
        ]);
    }

    public function test_user_cannot_switch_to_unauthorized_business(): void
    {
        $user = User::factory()->create();
        $allowed = $this->business('Moje OSVČ', '12345678', 'business_1');
        $forbidden = $this->business('Cizí OSVČ', '87654321', 'business_2');
        $user->businesses()->attach($allowed, ['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('business.switch', $forbidden->uuid))
            ->assertForbidden();

        $this->assertDatabaseHas(BusinessSwitchAudit::class, [
            'user_id' => $user->id,
            'requested_business_uuid' => $forbidden->uuid,
            'result' => 'denied',
            'reason' => 'access_denied',
        ]);
    }

    public function test_disallowed_database_connection_is_rejected_even_for_assigned_business(): void
    {
        $user = User::factory()->create();
        $business = $this->business('Poškozený záznam', '12345678', 'attacker_connection');
        $user->businesses()->attach($business, ['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('business.switch', $business->uuid))
            ->assertForbidden();

        $this->assertDatabaseHas(BusinessSwitchAudit::class, [
            'requested_business_uuid' => $business->uuid,
            'result' => 'denied',
            'reason' => 'connection_not_allowed',
        ]);
    }

    public function test_accounting_sections_are_blocked_without_active_business(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('invoices.index'))
            ->assertForbidden();
    }

    public function test_dashboard_displays_correct_business_name_and_registration_number(): void
    {
        $user = User::factory()->create();
        $business = $this->business('Správná OSVČ', '12345678', 'business_1');
        $user->businesses()->attach($business, ['role' => 'admin']);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Správná OSVČ')
            ->assertSee('IČO 12345678');
    }

    public function test_invalid_session_business_is_replaced_by_first_allowed_business(): void
    {
        $user = User::factory()->create();
        $business = $this->business('Povolená OSVČ', '12345678', 'business_1');
        $user->businesses()->attach($business, ['role' => 'admin']);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => (string) Str::uuid()])
            ->get('/dashboard')
            ->assertOk()
            ->assertSessionHas(config('business.session_key'), $business->uuid);
    }

    private function business(
        string $name,
        string $registrationNumber,
        string $connection,
        int $sortOrder = 1,
    ): Business {
        return Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => $name,
            'registration_number' => $registrationNumber,
            'short_label' => Str::limit($name, 20, ''),
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }
}
