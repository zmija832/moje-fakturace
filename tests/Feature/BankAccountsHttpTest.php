<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\User;
use App\Services\Business\BankAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class BankAccountsHttpTest extends TestCase
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

    public function test_guest_is_redirected_and_user_without_active_business_is_forbidden(): void
    {
        $this->get(route('bank-accounts.index'))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('bank-accounts.index'))
            ->assertForbidden();
    }

    public function test_viewer_can_view_accounts_but_cannot_mutate_them(): void
    {
        [$user, $business] = $this->userWithBusiness('viewer', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $account = app(BankAccountService::class)->create($this->validPayload());
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->get(route('bank-accounts.index'))
            ->assertOk()
            ->assertSee('Bankovní účty')
            ->assertSee('Provozní účet')
            ->assertSee('upravovat je může pouze administrátor')
            ->assertDontSee('Přidat účet');

        $this->post(route('bank-accounts.store'), $this->validPayload(['name' => 'Zakázaný']))
            ->assertForbidden();
        $this->patch(route('bank-accounts.set-default', $account->uuid))
            ->assertForbidden();
        $this->patch(route('bank-accounts.archive', $account->uuid))
            ->assertForbidden();

        $this->assertSame(1, DB::connection('business_1')->table('bank_accounts')->count());
    }

    public function test_administrator_can_create_and_update_normalized_account(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->post(route('bank-accounts.store').'?connection=business_2', $this->validPayload([
                'name' => '  Firemní CZK  ',
                'domestic_account_number' => ' 000123 456 ',
                'bank_code' => ' 0800 ',
                'iban' => ' cz65 0800 0000 1920 0014 5399 ',
                'bic' => ' giba cz px ',
                'connection' => 'business_2',
                'connection_name' => 'business_2',
                'uuid' => (string) Str::uuid(),
                'archived_at' => now()->toDateTimeString(),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('bank-accounts.index'));

        $row = DB::connection('business_1')->table('bank_accounts')->first();
        $this->assertSame('Firemní CZK', $row->name);
        $this->assertSame('000123456', $row->domestic_account_number);
        $this->assertSame('0800', $row->bank_code);
        $this->assertSame('CZ6508000000192000145399', $row->iban);
        $this->assertSame('GIBACZPX', $row->bic);
        $this->assertNull($row->archived_at);
        $this->assertSame(0, DB::connection('business_2')->table('bank_accounts')->count());
        $this->assertSame('central', DB::getDefaultConnection());

        $this->put(route('bank-accounts.update', $row->uuid), $this->validPayload([
            'name' => 'Upravený účet',
            'note' => 'Nová poznámka',
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('bank-accounts.index'));

        $this->assertDatabaseHas('bank_accounts', [
            'uuid' => $row->uuid,
            'name' => 'Upravený účet',
            'note' => 'Nová poznámka',
        ], 'business_1');
    }

    public function test_administrator_can_change_default_and_manage_lifecycle(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $first = app(BankAccountService::class)->create($this->validPayload(['name' => 'První účet']));
        $second = app(BankAccountService::class)->create($this->validPayload([
            'name' => 'Druhý účet',
            'domestic_account_number' => '987654321',
        ]));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)->withSession($this->businessSession($business));

        $this->patch(route('bank-accounts.set-default', $first->uuid))
            ->assertRedirect(route('bank-accounts.index'));
        $this->patch(route('bank-accounts.set-default', $second->uuid))
            ->assertSessionHas('status', 'Výchozí bankovní účet byl změněn.');
        $this->assertDatabaseHas('bank_account_defaults', [
            'currency' => 'CZK',
            'bank_account_id' => $second->id,
        ], 'business_1');

        $this->patch(route('bank-accounts.deactivate', $second->uuid))
            ->assertSessionHas('status', 'Bankovní účet byl deaktivován.');
        $this->assertSame(0, DB::connection('business_1')->table('bank_account_defaults')->count());

        $this->patch(route('bank-accounts.activate', $second->uuid))
            ->assertSessionHas('status', 'Bankovní účet byl aktivován.');
        $this->patch(route('bank-accounts.archive', $second->uuid))
            ->assertSessionHas('status', 'Bankovní účet byl archivován.');

        $row = DB::connection('business_1')->table('bank_accounts')->where('id', $second->id)->first();
        $this->assertFalse((bool) $row->is_active);
        $this->assertNotNull($row->archived_at);
        $this->assertSame(2, DB::connection('business_1')->table('bank_accounts')->count());
    }

    public function test_index_separates_archived_accounts_and_marks_default_account(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $current = app(BankAccountService::class)->create($this->validPayload(['name' => 'Aktuální účet']));
        app(BankAccountService::class)->setDefault($current->uuid);
        $archived = app(BankAccountService::class)->create($this->validPayload([
            'name' => 'Historický účet',
            'domestic_account_number' => '987654321',
        ]));
        app(BankAccountService::class)->archive($archived->uuid);
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->get(route('bank-accounts.index'))
            ->assertOk()
            ->assertSee('Aktuální účet')
            ->assertSee('Výchozí pro Kč')
            ->assertSee('Archivované účty')
            ->assertSee('Historický účet');
    }

    public function test_uuid_from_another_business_is_not_visible_or_mutable(): void
    {
        [$user, $businessOne] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        $businessTwo = $this->createBusiness(BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessTwo);
        $foreign = app(BankAccountService::class)->create($this->validPayload(['name' => 'Cizí účet']));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)->withSession($this->businessSession($businessOne));

        $this->get(route('bank-accounts.edit', $foreign->uuid))->assertNotFound();
        $this->put(route('bank-accounts.update', $foreign->uuid), $this->validPayload([
            'name' => 'Pokus o změnu',
        ]))->assertNotFound();
        $this->patch(route('bank-accounts.set-default', $foreign->uuid))->assertNotFound();
        $this->patch(route('bank-accounts.archive', $foreign->uuid))->assertNotFound();

        $this->assertSame(0, DB::connection('business_1')->table('bank_accounts')->count());
        $this->assertSame(
            'Cizí účet',
            DB::connection('business_2')->table('bank_accounts')->where('uuid', $foreign->uuid)->value('name'),
        );
    }

    public function test_user_without_business_membership_cannot_read_accounts(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness(BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->get(route('bank-accounts.index'))
            ->assertForbidden();
    }

    #[DataProvider('invalidPayloads')]
    public function test_store_validates_bank_account_fields(string $field, mixed $value, array $errors): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->post(route('bank-accounts.store'), $this->validPayload([$field => $value]))
            ->assertSessionHasErrors($errors);

        $this->assertSame(0, DB::connection('business_1')->table('bank_accounts')->count());
    }

    public function test_domestic_number_or_iban_is_required(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->post(route('bank-accounts.store'), $this->validPayload([
                'domestic_account_number' => null,
                'bank_code' => null,
                'iban' => null,
            ]))
            ->assertSessionHasErrors(['domestic_account_number', 'iban']);
    }

    public function test_changing_currency_of_default_account_returns_validation_error(): void
    {
        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $account = app(BankAccountService::class)->create($this->validPayload());
        app(BankAccountService::class)->setDefault($account->uuid);
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->put(route('bank-accounts.update', $account->uuid), $this->validPayload([
                'currency' => 'EUR',
                'iban' => 'DE89370400440532013000',
            ]))
            ->assertSessionHasErrors('currency');

        $this->assertSame(
            'CZK',
            DB::connection('business_1')->table('bank_accounts')->where('uuid', $account->uuid)->value('currency'),
        );
    }

    public function test_mutating_routes_are_in_web_middleware_group_and_forms_contain_csrf_tokens(): void
    {
        foreach ([
            'bank-accounts.store',
            'bank-accounts.update',
            'bank-accounts.set-default',
            'bank-accounts.deactivate',
            'bank-accounts.activate',
            'bank-accounts.archive',
        ] as $routeName) {
            $middleware = app('router')->getRoutes()->getByName($routeName)->gatherMiddleware();

            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
        }

        [$user, $business] = $this->userWithBusiness('admin', BusinessConnection::Business1);

        $this->actingAs($user)
            ->withSession($this->businessSession($business))
            ->get(route('bank-accounts.create'))
            ->assertOk()
            ->assertSee('name="_token"', false);
    }

    /**
     * @return array<string, array{string, mixed, list<string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => ['name', '', ['name']],
            'unsupported currency' => ['currency', 'USD', ['currency']],
            'prefix with letters' => ['domestic_prefix', '12A', ['domestic_prefix']],
            'number with letters' => ['domestic_account_number', '12AB34', ['domestic_account_number']],
            'bank code not four digits' => ['bank_code', '800', ['bank_code']],
            'invalid IBAN checksum' => ['iban', 'CZ6508000000192000145398', ['iban']],
            'invalid BIC' => ['bic', 'NOT-A-BIC', ['bic']],
            'negative sort order' => ['sort_order', -1, ['sort_order']],
            'invalid active flag' => ['is_active', 'maybe', ['is_active']],
        ];
    }

    /**
     * @return array{User, Business}
     */
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

    /**
     * @return array<string, string>
     */
    private function businessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Provozní účet',
            'domestic_prefix' => null,
            'domestic_account_number' => '123456789',
            'bank_code' => '0800',
            'iban' => 'CZ6508000000192000145399',
            'bic' => 'GIBACZPX',
            'currency' => 'CZK',
            'is_active' => '1',
            'sort_order' => 10,
            'note' => 'Testovací poznámka',
        ], $overrides);
    }
}
