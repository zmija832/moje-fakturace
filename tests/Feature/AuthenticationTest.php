<?php

namespace Tests\Feature;

use App\Models\LoginAudit;
use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_public_registration_is_not_available(): void
    {
        $this->get('/registrace')->assertNotFound();
        $this->post('/registrace')->assertNotFound();
        $this->assertFalse(app('router')->has('register'));
    }

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_open_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($user->name);
    }

    public function test_successful_and_failed_login_are_audited_without_password(): void
    {
        $user = User::factory()->create([
            'email' => 'spravce@example.test',
            'password' => 'Bezpecne-heslo-123',
        ]);

        $this->post('/prihlaseni', [
            'email' => $user->email,
            'password' => 'spatne-heslo',
        ])->assertSessionHasErrors('email');

        $this->post('/prihlaseni', [
            'email' => $user->email,
            'password' => 'Bezpecne-heslo-123',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas(LoginAudit::class, ['event' => 'failed']);
        $this->assertDatabaseHas(LoginAudit::class, ['event' => 'login', 'user_id' => $user->id]);
        $this->assertDatabaseMissing(LoginAudit::class, ['attempted_email_hash' => 'spatne-heslo']);
    }
}
