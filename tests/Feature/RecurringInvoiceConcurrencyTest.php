<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Services\Business\RecurringInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class RecurringInvoiceConcurrencyTest extends TestCase
{
    use BuildsBusinessProcessEnvironment,CreatesInvoiceDeliveryFixtures,InteractsWithBusinessDatabases;

    protected bool $businessDatabaseTransactions = false;

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

    public function test_two_workers_create_only_one_invoice_for_same_period(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [, $client,$account] = $this->createIssuedInvoice(false);
        $template = app(RecurringInvoiceService::class)->create(['name' => 'Souběh', 'client_uuid' => $client->uuid, 'bank_account_uuid' => $account->uuid, 'currency' => 'CZK', 'payment_method' => 'bank_transfer', 'due_days' => 14, 'interval_months' => 1, 'next_run_on' => '2026-09-01', 'mode' => 'draft', 'auto_send' => false, 'invoice_discount_type' => 'none', 'items' => [['description' => 'Servis', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '100', 'discount_type' => 'none']]]);
        $barrier = storage_path('framework/testing/recurring-'.Str::uuid());
        $env = $this->businessChildProcessEnvironment();
        $processes = [];
        foreach ([1, 2] as $_) {
            $processes[] = new Process($this->businessPhpCommand(base_path('tests/Support/run-recurring-invoice.php'), ['business_1', $business->uuid, $template->uuid, $barrier]), base_path(), $env);
        }
        try {
            foreach ($processes as $p) {
                $p->setTimeout(90);
                $p->start();
            }file_put_contents($barrier, 'start');
            foreach ($processes as $p) {
                $p->wait();
            }$this->assertSame([0, 0], array_map(fn ($p) => $p->getExitCode() ?? -1, $processes), implode("\n", array_map(fn ($p) => $p->getErrorOutput(), $processes)));
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop(1);
                }$this->assertFalse($p->isRunning());
            }if (is_file($barrier)) {
                unlink($barrier);
            }
        }
        $this->assertSame(1, DB::connection('business_1')->table('recurring_invoice_runs')->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoices')->where('uuid', DB::connection('business_1')->table('recurring_invoice_runs')->value('invoice_uuid'))->count());
    }
}
