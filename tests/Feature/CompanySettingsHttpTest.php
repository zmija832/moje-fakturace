<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business;
use App\Models\User;
use App\Services\Business\CompanySettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class CompanySettingsHttpTest extends TestCase
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
            $this->ensureSafeTestDatabases();

            foreach (BusinessConnection::cases() as $connection) {
                if (Schema::connection($connection->connectionName())->hasTable('company_settings')) {
                    DB::connection($connection->connectionName())->table('company_settings')->delete();
                }
            }

            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('company-settings.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_page_is_forbidden_without_active_business(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('company-settings.edit'))
            ->assertForbidden();
    }

    public function test_administrator_can_view_page_without_get_request_writing_data(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->get(route('company-settings.edit'))
            ->assertOk()
            ->assertSee('Nastavení subjektu')
            ->assertSee('Oficiální název')
            ->assertSee('Výchozí nastavení dokladů');

        $this->assertSame(0, DB::connection('business_1')->table('company_settings')->count());
    }

    public function test_administrator_can_store_company_settings(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->put(route('company-settings.update'), $this->validPayload([
                'legal_name' => 'Uložený subjekt',
            ]))
            ->assertRedirect(route('company-settings.edit'))
            ->assertSessionHas('status', 'Nastavení subjektu bylo uloženo.');

        $this->assertDatabaseHas('company_settings', [
            'singleton_key' => '1',
            'legal_name' => 'Uložený subjekt',
            'registration_number' => '12345678',
            'is_vat_payer' => false,
        ], 'business_1');
        $this->assertSame(0, DB::connection('business_2')->table('company_settings')->count());
        $this->assertFalse(Schema::connection('central')->hasTable('company_settings'));
    }

    public function test_non_administrator_can_view_but_cannot_update(): void
    {
        [$user, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->get(route('company-settings.edit'))
            ->assertOk()
            ->assertSee('upravovat je může pouze administrátor');

        $this->put(route('company-settings.update'), $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, DB::connection('business_1')->table('company_settings')->count());
    }

    public function test_user_without_business_access_receives_forbidden_response(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness(BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->get(route('company-settings.edit'))
            ->assertForbidden();
    }

    public function test_request_cannot_change_connection_or_singleton_key(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $payload = $this->validPayload([
            'legal_name' => 'Pouze první databáze',
            'connection' => 'business_2',
            'connection_name' => 'business_2',
            'singleton_key' => '2',
        ]);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->put(route('company-settings.update').'?connection=business_2', $payload)
            ->assertRedirect(route('company-settings.edit'));

        $this->assertSame(
            'Pouze první databáze',
            DB::connection('business_1')->table('company_settings')->value('legal_name'),
        );
        $this->assertSame(
            '1',
            DB::connection('business_1')->table('company_settings')->value('singleton_key'),
        );
        $this->assertSame(0, DB::connection('business_2')->table('company_settings')->count());
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_updating_business_1_does_not_change_existing_business_2_settings(): void
    {
        [$user, $businessOne] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $businessTwo = $this->createBusiness(BusinessConnection::Business2);

        app(ActiveBusinessContext::class)->set($businessTwo);
        app(CompanySettingsService::class)->save($this->validPayload([
            'legal_name' => 'Původní druhý subjekt',
            'registration_number' => '87654321',
        ]));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $businessOne->uuid])
            ->put(route('company-settings.update'), $this->validPayload([
                'legal_name' => 'Změněný první subjekt',
            ]))
            ->assertRedirect(route('company-settings.edit'));

        $this->assertSame(
            'Změněný první subjekt',
            DB::connection('business_1')->table('company_settings')->value('legal_name'),
        );
        $this->assertSame(
            'Původní druhý subjekt',
            DB::connection('business_2')->table('company_settings')->value('legal_name'),
        );
    }

    public function test_required_fields_are_validated(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->put(route('company-settings.update'), [])
            ->assertSessionHasErrors([
                'legal_name',
                'registration_number',
                'street',
                'city',
                'postal_code',
                'country_code',
                'email',
                'default_currency',
                'document_locale',
                'timezone',
                'default_due_days',
                'default_payment_method',
            ]);
    }

    #[DataProvider('invalidFieldValues')]
    public function test_invalid_allowed_values_are_rejected(string $field, mixed $value): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->put(route('company-settings.update'), $this->validPayload([$field => $value]))
            ->assertSessionHasErrors($field);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidFieldValues(): array
    {
        return [
            'registration number with letters' => ['registration_number', '12AB5678'],
            'invalid email' => ['email', 'neplatny-email'],
            'invalid URL' => ['website', 'neni-url'],
            'unsupported country' => ['country_code', 'DE'],
            'unsupported currency' => ['default_currency', 'USD'],
            'unsupported locale' => ['document_locale', 'en'],
            'unsupported timezone' => ['timezone', 'UTC'],
            'negative due days' => ['default_due_days', -1],
            'too many due days' => ['default_due_days', 366],
            'invalid payment method' => ['default_payment_method', 'crypto'],
            'invalid VAT payer flag' => ['is_vat_payer', 'maybe'],
        ];
    }

    public function test_vat_payer_requires_vat_id_and_validates_registration_date(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->put(route('company-settings.update'), $this->validPayload([
                'is_vat_payer' => '1',
                'vat_id' => null,
                'vat_registered_on' => 'neni-datum',
            ]))
            ->assertSessionHasErrors(['vat_id', 'vat_registered_on']);
    }

    public function test_non_vat_payer_can_leave_vat_fields_empty(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession([config('business.session_key') => $business->uuid])
            ->put(route('company-settings.update'), $this->validPayload([
                'is_vat_payer' => '0',
                'vat_id' => null,
                'vat_registered_on' => null,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('company-settings.edit'));
    }

    /**
     * @return array{User, Business}
     */
    private function userWithBusiness(
        string $role,
        BusinessConnection $connection,
    ): array {
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'legal_name' => 'Testovací subjekt',
            'additional_name' => null,
            'registration_number' => '12345678',
            'tax_id' => 'CZ12345678',
            'vat_id' => null,
            'street' => 'Testovací',
            'house_number' => '1',
            'orientation_number' => null,
            'city' => 'Praha',
            'postal_code' => '11000',
            'country_code' => 'CZ',
            'email' => 'firma@example.test',
            'phone' => '+420 123 456 789',
            'website' => 'https://example.test',
            'default_currency' => 'CZK',
            'document_locale' => 'cs',
            'timezone' => 'Europe/Prague',
            'is_vat_payer' => '0',
            'vat_registered_on' => null,
            'default_due_days' => 14,
            'default_payment_method' => DefaultPaymentMethod::BankTransfer->value,
            'invoice_intro' => 'Úvodní text',
            'invoice_outro' => 'Závěrečný text',
        ], $overrides);
    }
}
