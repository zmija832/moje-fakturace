<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\Client;
use App\Models\User;
use App\Services\Business\ClientService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class ClientsHttpTest extends TestCase
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
                if (Schema::connection($connection->connectionName())->hasTable('clients')) {
                    DB::connection($connection->connectionName())->table('clients')->delete();
                }
            }
            app(ActiveBusinessContext::class)->clear();
        } finally {
            parent::tearDown();
        }
    }

    public function test_guest_is_redirected_and_missing_or_unauthorized_business_is_forbidden(): void
    {
        $this->get(route('clients.index'))->assertRedirect(route('login'));

        $userWithoutBusiness = User::factory()->create();
        $this->actingAs($userWithoutBusiness)
            ->get(route('clients.index'))->assertForbidden();
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('clients.index'), false);

        $business = $this->createBusiness(BusinessConnection::Business1);
        $this->withSession($this->businessSession($business))
            ->get(route('clients.index'))->assertForbidden();
    }

    public function test_viewer_can_view_list_and_detail_but_cannot_mutate_or_see_actions(): void
    {
        [$user, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $client = app(ClientService::class)->create($this->companyPayload());
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)->withSession($this->businessSession($business));
        $this->get(route('clients.index'))->assertOk()->assertSee('Testovací firma')
            ->assertDontSee('Přidat klienta')->assertDontSee('Archivovat');
        $this->get(route('clients.show', $client->uuid))->assertOk()->assertSee('Fakturační');
        $this->post(route('clients.store'), $this->companyPayload())->assertForbidden();
        $this->put(route('clients.update', $client->uuid), $this->companyPayload())->assertForbidden();
        $this->patch(route('clients.archive', $client->uuid))->assertForbidden();

        $unknownRole = User::factory()->create();
        $unknownRole->businesses()->attach($business, ['role' => 'unknown']);
        $this->actingAs($unknownRole)->withSession($this->businessSession($business))
            ->post(route('clients.store'), $this->companyPayload())->assertForbidden();
    }

    public function test_admin_creates_company_and_person_with_generated_names_and_normalization(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $this->actingAs($user)->withSession($this->businessSession($business));

        $this->post(route('clients.store').'?connection=business_2', $this->companyPayload([
            'display_name' => '', 'company_name' => '  Alfa s.r.o. ', 'email' => ' INFO@ALFA.TEST ',
            'registration_number' => ' 001 234 56 ', 'country_code' => 'cz',
            'delivery_name' => 'Sklad', 'delivery_street' => 'Dodací',
            'delivery_city' => 'Brno', 'delivery_postal_code' => '60200',
            'delivery_country_code' => 'sk',
            'connection' => 'business_2', 'uuid' => (string) Str::uuid(), 'archived_at' => now(),
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', 'Klient byl vytvořen.');

        $this->post(route('clients.store'), $this->personPayload([
            'display_name' => null, 'first_name' => ' Eva ', 'last_name' => ' Malá ',
            'company_name' => 'Skrytá firma', 'registration_number' => '12345678',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('clients', [
            'display_name' => 'Alfa s.r.o.', 'company_name' => 'Alfa s.r.o.',
            'registration_number' => '00123456', 'email' => 'info@alfa.test',
            'country_code' => 'CZ', 'delivery_country_code' => 'SK', 'archived_at' => null,
        ], 'business_1');
        $this->assertDatabaseHas('clients', [
            'type' => 'person', 'display_name' => 'Eva Malá', 'company_name' => null,
            'registration_number' => null,
        ], 'business_1');
        $this->assertSame(0, DB::connection('business_2')->table('clients')->count());
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_admin_quick_creates_active_client_with_json_without_accepting_technical_fields(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $payload = $this->companyPayload([
            'display_name' => '', 'company_name' => '  Rychlý klient s.r.o. ',
            'email' => ' RYCHLY@EXAMPLE.TEST ', 'is_active' => '0',
            'connection' => 'business_2', 'business_id' => 999, 'uuid' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($user)->withSession($this->businessSession($business))
            ->postJson(route('clients.store').'?connection=business_2', $payload)
            ->assertCreated()
            ->assertJsonPath('client.display_name', 'Rychlý klient s.r.o.')
            ->assertJsonPath('client.registration_number', '12345678')
            ->assertJsonStructure(['client' => [
                'uuid', 'display_name', 'registration_number', 'default_currency',
                'default_due_days', 'default_payment_method',
            ]]);

        $uuid = $response->json('client.uuid');
        $this->assertTrue(Str::isUuid($uuid));
        $this->assertDatabaseHas('clients', [
            'uuid' => $uuid, 'display_name' => 'Rychlý klient s.r.o.',
            'email' => 'rychly@example.test', 'is_active' => true,
            'street' => 'Testovací', 'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
        ], 'business_1');
        $this->assertSame(0, DB::connection('business_2')->table('clients')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'client.created', 'auditable_uuid' => $uuid,
        ], 'business_1');
        $this->assertArrayNotHasKey('id', $response->json('client'));
        $this->assertArrayNotHasKey('connection', $response->json('client'));
        $this->assertArrayNotHasKey('business_id', $response->json('client'));
    }

    public function test_quick_client_json_keeps_existing_address_validation(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $payload = $this->companyPayload([
            'street' => '', 'city' => '', 'postal_code' => '', 'country_code' => '',
        ]);
        unset($payload['is_active']);

        $response = $this->actingAs($user)->withSession($this->businessSession($business))
            ->postJson(route('clients.store'), $payload)
            ->assertUnprocessable();

        $validationErrors = $response->json('errors');
        foreach (['street', 'city', 'postal_code', 'country_code'] as $field) {
            $this->assertArrayHasKey($field, $validationErrors);
        }

        $this->assertSame(0, DB::connection('business_1')->table('clients')->count());
    }

    public function test_admin_loads_normalized_ares_subject_by_ico_and_response_is_cached_without_database_writes(): void
    {
        Cache::clear();
        Http::preventStrayRequests();
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345678' => Http::response([
                'ico' => '12345678',
                'obchodniJmeno' => "  Testovací\nARES s.r.o.  ",
                'dic' => 'CZ12345678',
                'dicSkDph' => 'CZ99999999',
                'sidlo' => [
                    'kodStatu' => '203', 'nazevObce' => 'Praha',
                    'nazevUlice' => 'Dlouhá', 'cisloDomovni' => 12,
                    'cisloOrientacni' => 3, 'cisloOrientacniPismeno' => 'a',
                    'psc' => 11000, 'textovaAdresa' => 'Tento text se nesmí parsovat',
                ],
                'untrusted' => '<script>alert(1)</script>',
            ]),
        ]);

        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $this->actingAs($user)->withSession($this->businessSession($business));

        foreach (range(1, 2) as $attempt) {
            $this->postJson(route('clients.ares.lookup'), ['ico' => '1234 5678'])
                ->assertOk()
                ->assertExactJson([
                    'subject' => [
                        'company_name' => 'Testovací ARES s.r.o.',
                        'registration_number' => '12345678',
                        'tax_id' => 'CZ12345678',
                        'street' => 'Dlouhá 12/3a',
                        'city' => 'Praha',
                        'postal_code' => '11000',
                        'country_code' => 'CZ',
                    ],
                    'warnings' => [],
                ]);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345678');
        $this->assertSame(0, DB::connection('business_1')->table('clients')->count());
        $this->assertSame(0, DB::connection('business_1')->table('audit_logs')->count());
        $this->assertSame(0, DB::connection('business_2')->table('clients')->count());
    }

    public function test_ares_lookup_returns_available_fields_and_warning_without_deriving_missing_tax_id(): void
    {
        Cache::clear();
        Http::preventStrayRequests();
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/87654321' => Http::response([
                'ico' => '87654321',
                'obchodniJmeno' => 'Neúplný subjekt',
                'sidlo' => ['nazevObce' => 'Brno', 'pscTxt' => '602 00'],
            ]),
        ]);

        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $this->actingAs($user)->withSession($this->businessSession($business))
            ->postJson(route('clients.ares.lookup'), ['ico' => '87654321'])
            ->assertOk()
            ->assertJsonPath('subject.company_name', 'Neúplný subjekt')
            ->assertJsonPath('subject.registration_number', '87654321')
            ->assertJsonPath('subject.tax_id', null)
            ->assertJsonPath('subject.street', null)
            ->assertJsonPath('subject.city', 'Brno')
            ->assertJsonPath('subject.postal_code', '60200')
            ->assertJsonPath('subject.country_code', null)
            ->assertJsonCount(1, 'warnings');

        $this->assertSame(0, DB::connection('business_1')->table('clients')->count());
    }

    public function test_ares_lookup_is_authorized_tenant_safe_and_handles_validation_and_unavailability(): void
    {
        Cache::clear();
        Http::preventStrayRequests();

        $this->postJson(route('clients.ares.lookup'), ['ico' => '12345678'])
            ->assertRedirect(route('login'));

        [$viewer, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);
        $this->actingAs($viewer)->withSession($this->businessSession($business))
            ->postJson(route('clients.ares.lookup'), ['ico' => '12345678'])
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->businesses()->attach($business, ['role' => 'admin']);
        $this->actingAs($admin)->withSession($this->businessSession($business))
            ->postJson(route('clients.ares.lookup'), ['ico' => '123', 'connection' => 'business_2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ico', 'connection']);
        Http::assertNothingSent();

        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/12345678' => Http::response([
                'kod' => 'NENALEZENO', 'popis' => 'Záznam nenalezen',
            ], 404),
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/87654321' => Http::response([], 500),
        ]);

        $this->actingAs($admin)->withSession($this->businessSession($business))
            ->postJson(route('clients.ares.lookup'), ['ico' => '12345678'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Subjekt s tímto IČO nebyl v ARES nalezen.');
        $this->postJson(route('clients.ares.lookup'), ['ico' => '87654321'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.');

        Cache::clear();
        Http::fake([
            'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/11111111' => Http::failedConnection(),
        ]);
        $this->postJson(route('clients.ares.lookup'), ['ico' => '11111111'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'ARES nyní není dostupný. Údaje můžete vyplnit ručně.');

        $this->assertSame('central', DB::getDefaultConnection());
        $this->assertSame(0, DB::connection('business_1')->table('clients')->count());
        $this->assertSame(0, DB::connection('business_2')->table('clients')->count());
    }

    public function test_admin_updates_lifecycle_and_archived_client_is_read_only_but_visible(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $client = app(ClientService::class)->create($this->companyPayload(['display_name' => 'Ruční název']));
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($user)->withSession($this->businessSession($business));

        $this->put(route('clients.update', $client->uuid), $this->companyPayload([
            'display_name' => 'Ruční název', 'company_name' => 'Nový právní název',
        ]))->assertSessionHas('status', 'Klient byl uložen.');
        $this->assertDatabaseHas('clients', ['uuid' => $client->uuid, 'display_name' => 'Ruční název'], 'business_1');

        $this->patch(route('clients.deactivate', $client->uuid))->assertSessionHas('status', 'Klient byl deaktivován.');
        $this->patch(route('clients.activate', $client->uuid))->assertSessionHas('status', 'Klient byl aktivován.');
        $this->patch(route('clients.archive', $client->uuid))->assertRedirect(route('clients.index', ['status' => 'archived']));

        $this->assertSame(1, DB::connection('business_1')->table('clients')->count());
        $this->get(route('clients.show', $client->uuid))->assertOk()->assertSee('Archivovaný klient');
        $this->get(route('clients.edit', $client->uuid))->assertNotFound();
        $this->put(route('clients.update', $client->uuid), $this->companyPayload())->assertNotFound();
        $this->patch(route('clients.activate', $client->uuid))->assertSessionHasErrors('client');
    }

    public function test_validation_covers_types_addresses_options_and_limits_and_preserves_input(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $this->actingAs($user)->withSession($this->businessSession($business));

        $cases = [
            [$this->companyPayload(['street' => '', 'city' => '', 'postal_code' => '', 'country_code' => '']), ['street', 'city', 'postal_code', 'country_code']],
            [$this->companyPayload(['company_name' => '']), ['company_name']],
            [$this->personPayload(['first_name' => '', 'last_name' => '']), ['first_name', 'last_name']],
            [$this->companyPayload(['type' => 'unknown']), ['type']],
            [$this->companyPayload(['email' => 'neni-email']), ['email']],
            [$this->companyPayload(['website' => 'neni-url']), ['website']],
            [$this->companyPayload(['country_code' => 'DE']), ['country_code']],
            [$this->companyPayload(['default_currency' => 'USD']), ['default_currency']],
            [$this->companyPayload(['language' => 'en']), ['language']],
            [$this->companyPayload(['default_payment_method' => 'crypto']), ['default_payment_method']],
            [$this->companyPayload(['default_due_days' => 366]), ['default_due_days']],
            [$this->companyPayload(['registration_number' => '12AB']), ['registration_number']],
            [$this->companyPayload(['contact_person' => str_repeat('x', 256)]), ['contact_person']],
            [$this->companyPayload(['delivery_name' => 'Sklad']), ['delivery_street', 'delivery_city', 'delivery_postal_code', 'delivery_country_code']],
        ];

        foreach ($cases as [$payload, $errors]) {
            $this->post(route('clients.store'), $payload)->assertSessionHasErrors($errors);
        }

        $this->post(route('clients.store'), $this->companyPayload(['company_name' => '']))
            ->assertSessionHasInput('company_name', '');
        $this->assertSame(0, DB::connection('business_1')->table('clients')->count());
    }

    public function test_technical_fields_and_connection_parameter_cannot_be_injected(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $fakeUuid = (string) Str::uuid();
        $this->actingAs($user)->withSession($this->businessSession($business))
            ->post(route('clients.store').'?connection=business_2', $this->companyPayload([
                'uuid' => $fakeUuid, 'connection' => 'business_2', 'connection_name' => 'business_2',
                'archived_at' => now()->toDateTimeString(), 'created_at' => '2000-01-01',
            ]))->assertSessionHasNoErrors();

        $row = DB::connection('business_1')->table('clients')->first();
        $this->assertNotSame($fakeUuid, $row->uuid);
        $this->assertNull($row->archived_at);
        $this->assertSame(0, DB::connection('business_2')->table('clients')->count());
    }

    public function test_same_uuid_in_both_databases_updates_only_active_business(): void
    {
        [$user, $businessOne] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $businessTwo = $this->createBusiness(BusinessConnection::Business2);
        $uuid = (string) Str::uuid();

        app(ActiveBusinessContext::class)->set($businessOne);
        $this->forcedUuidClient($uuid, 'První původní');
        app(ActiveBusinessContext::class)->set($businessTwo);
        $this->forcedUuidClient($uuid, 'Druhý původní');
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)->withSession($this->businessSession($businessOne));
        $this->put(route('clients.update', $uuid), $this->companyPayload(['display_name' => 'První změněný']))
            ->assertSessionHasNoErrors();

        $this->assertSame('První změněný', DB::connection('business_1')->table('clients')->where('uuid', $uuid)->value('display_name'));
        $this->assertSame('Druhý původní', DB::connection('business_2')->table('clients')->where('uuid', $uuid)->value('display_name'));
    }

    public function test_foreign_uuid_returns_not_found_for_all_tenant_safe_reads_and_mutations(): void
    {
        [$user, $businessOne] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $businessTwo = $this->createBusiness(BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessTwo);
        $foreign = app(ClientService::class)->create($this->companyPayload(['display_name' => 'Cizí klient']));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)->withSession($this->businessSession($businessOne));
        $this->get(route('clients.show', $foreign->uuid))->assertNotFound();
        $this->get(route('clients.edit', $foreign->uuid))->assertNotFound();
        $this->put(route('clients.update', $foreign->uuid), $this->companyPayload())->assertNotFound();
        $this->patch(route('clients.archive', $foreign->uuid))->assertNotFound();
    }

    public function test_search_filters_safe_sort_and_pagination_preserve_query(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        for ($index = 1; $index <= 22; $index++) {
            app(ClientService::class)->create($this->companyPayload([
                'display_name' => sprintf('Klient %02d', $index),
                'company_name' => sprintf('Firma %02d', $index),
                'registration_number' => str_pad((string) $index, 8, '0', STR_PAD_LEFT),
            ]));
        }
        $person = app(ClientService::class)->create($this->personPayload(['display_name' => 'Hledaná osoba', 'city' => 'Brno']));
        app(ClientService::class)->archive($person->uuid);
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)->withSession($this->businessSession($business));
        $this->get(route('clients.index', ['search' => 'Klient', 'type' => 'company', 'status' => 'active']))
            ->assertOk()->assertSee('Klient 01')->assertSee('search=Klient', false)->assertSee('type=company', false);
        $this->get(route('clients.index', ['status' => 'archived']))
            ->assertOk()->assertSee('Hledaná osoba')->assertSee('Archivovaný');
        $this->get(route('clients.index', ['sort' => 'DROP TABLE clients', 'direction' => 'sideways']))
            ->assertOk();
        $this->get(route('clients.index'))->assertDontSee('Hledaná osoba');
    }

    public function test_forms_keep_csrf_and_routes_keep_required_middleware(): void
    {
        foreach (['clients.store', 'clients.update', 'clients.deactivate', 'clients.activate', 'clients.archive'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('business.context', $middleware);
            $this->assertContains('business.required', $middleware);
        }

        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $this->actingAs($user)->withSession($this->businessSession($business))
            ->get(route('clients.create'))->assertOk()->assertSee('name="_token"', false)
            ->assertSee('Dodací adresa')->assertSee('Výchozí nastavení fakturace')
            ->assertSee('Načíst z ARES')
            ->assertSee('aresClientForm', false);
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
            'uuid' => (string) Str::uuid(), 'display_name' => 'Subjekt '.$connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true,
            'sort_order' => $connection === BusinessConnection::Business1 ? 1 : 2,
        ]);
    }

    private function forcedUuidClient(string $uuid, string $name): Client
    {
        $client = new Client;
        $client->forceFill(['uuid' => $uuid]);
        $client->fill($this->companyPayload(['display_name' => $name]));
        $client->save();

        return $client;
    }

    /** @return array<string, string> */
    private function businessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    /** @param array<string, mixed> $overrides */
    private function companyPayload(array $overrides = []): array
    {
        return array_replace([
            'type' => 'company', 'display_name' => 'Testovací firma', 'company_name' => 'Testovací firma s.r.o.',
            'first_name' => null, 'last_name' => null, 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'vat_id' => null, 'email' => 'firma@example.test',
            'phone' => '+420 123 456 789', 'website' => 'https://example.test', 'contact_person' => 'Jana Nováková',
            'street' => 'Testovací', 'house_number' => '1', 'orientation_number' => null,
            'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
            'delivery_name' => null, 'delivery_street' => null, 'delivery_house_number' => null,
            'delivery_orientation_number' => null, 'delivery_city' => null,
            'delivery_postal_code' => null, 'delivery_country_code' => null,
            'default_currency' => 'CZK', 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'language' => 'cs',
            'note' => 'Poznámka', 'is_active' => '1',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function personPayload(array $overrides = []): array
    {
        return array_replace($this->companyPayload(), [
            'type' => 'person', 'display_name' => 'Jan Novák', 'company_name' => null,
            'first_name' => 'Jan', 'last_name' => 'Novák', 'registration_number' => null,
            'tax_id' => null, 'vat_id' => null, 'contact_person' => null,
        ], $overrides);
    }
}
