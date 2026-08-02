<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $immutableTables = [
        'invoice_revisions',
        'invoice_supplier_snapshots',
        'invoice_customer_snapshots',
        'invoice_bank_account_snapshots',
        'invoice_vat_snapshots',
        'invoice_items',
        'invoice_vat_summaries',
        'invoice_draft_operations',
    ];

    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        Schema::connection($connection)->create('invoice_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedInteger('revision_number');
            $table->char('currency', 3);
            $table->date('issued_on');
            $table->date('taxable_supply_on');
            $table->date('due_on');
            $table->string('payment_method', 32);
            $table->string('variable_symbol', 20)->nullable();
            $table->text('note')->nullable();
            $table->string('invoice_discount_type', 16)->default('none');
            $table->decimal('invoice_discount_value', 19, 4)->default(0);
            $table->decimal('invoice_discount_amount', 19, 4)->default(0);
            $table->decimal('subtotal_before_discount', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_base_total', 19, 4)->default(0);
            $table->decimal('vat_total', 19, 4)->default(0);
            $table->decimal('total_before_rounding', 19, 4)->default(0);
            $table->decimal('rounding_adjustment', 19, 4)->default(0);
            $table->decimal('grand_total', 19, 4)->default(0);
            $table->string('created_by_actor')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'revision_number'], 'invoice_revisions_number_unique');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_revision_id')->nullable()->after('status');
            $table->unsignedInteger('version')->default(1)->after('current_revision_id');
        });

        $this->dropSnapshotTriggers($connection);
        $this->createInitialRevisions($connection);
        $this->moveSnapshotsAndItemsToRevisions($connection);
        $this->createVatSummariesAndOperations($connection);
        $this->backfillCalculatedValues($connection);

        DB::connection($connection)->statement(
            'UPDATE invoices i JOIN invoice_revisions r ON r.invoice_id = i.id AND r.revision_number = 1 SET i.current_revision_id = r.id, i.version = 1',
        );

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->foreign('current_revision_id', 'invoices_current_revision_foreign')
                ->references('id')->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });

        $this->createImmutableTriggers($connection);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();

        if (DB::connection($connection)->table('invoices')->exists()) {
            throw new RuntimeException('Migraci revizí nelze vrátit, pokud existují faktury; downgrade by zahodil historické revize.');
        }

        $this->dropImmutableTriggers($connection);

        Schema::connection($connection)->table('invoices', function (Blueprint $table): void {
            $table->dropForeign('invoices_current_revision_foreign');
            $table->dropColumn(['current_revision_id', 'version']);
        });

        Schema::connection($connection)->dropIfExists('invoice_draft_operations');
        Schema::connection($connection)->dropIfExists('invoice_vat_summaries');

        $this->restorePartOneItems($connection);
        $this->restorePartOneVatSnapshots($connection);
        $this->restorePartOneSingleSnapshots($connection);

        Schema::connection($connection)->dropIfExists('invoice_revisions');
        $this->createPartOneSnapshotTriggers($connection);
    }

    private function createInitialRevisions(string $connection): void
    {
        foreach (DB::connection($connection)->table('invoices')->orderBy('id')->get() as $invoice) {
            DB::connection($connection)->table('invoice_revisions')->insert([
                'uuid' => (string) Str::uuid(),
                'invoice_id' => $invoice->id,
                'revision_number' => 1,
                'currency' => $invoice->currency,
                'issued_on' => $invoice->issued_on,
                'taxable_supply_on' => $invoice->taxable_supply_on,
                'due_on' => $invoice->due_on,
                'payment_method' => $invoice->payment_method,
                'variable_symbol' => $invoice->variable_symbol,
                'note' => $invoice->note,
                'created_at' => $invoice->created_at,
                'updated_at' => $invoice->updated_at,
            ]);
        }
    }

    private function moveSnapshotsAndItemsToRevisions(string $connection): void
    {
        foreach (['invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots'] as $table) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
            });
            DB::connection($connection)->statement(
                "UPDATE {$table} s JOIN invoice_revisions r ON r.invoice_id = s.invoice_id AND r.revision_number = 1 SET s.invoice_revision_id = r.id",
            );
            Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign("{$table}_invoice_id_foreign");
            });
            DB::connection($connection)->statement("ALTER TABLE `{$table}` DROP PRIMARY KEY, DROP COLUMN `invoice_id`, MODIFY `invoice_revision_id` BIGINT UNSIGNED NOT NULL, ADD PRIMARY KEY (`invoice_revision_id`)");
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('invoice_revision_id')->references('id')->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
            });
        }

        Schema::connection($connection)->table('invoice_vat_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
        });
        DB::connection($connection)->statement(
            'UPDATE invoice_vat_snapshots s JOIN invoice_revisions r ON r.invoice_id = s.invoice_id AND r.revision_number = 1 SET s.invoice_revision_id = r.id',
        );
        Schema::connection($connection)->table('invoice_vat_snapshots', function (Blueprint $table): void {
            $table->dropForeign('invoice_vat_snapshots_invoice_id_foreign');
            $table->dropUnique('invoice_vat_snapshots_source_unique');
            $table->dropColumn('invoice_id');
        });
        DB::connection($connection)->statement('ALTER TABLE `invoice_vat_snapshots` MODIFY `invoice_revision_id` BIGINT UNSIGNED NOT NULL');
        Schema::connection($connection)->table('invoice_vat_snapshots', function (Blueprint $table): void {
            $table->unique(['invoice_revision_id', 'source_vat_rate_uuid'], 'invoice_vat_snapshots_source_unique');
            $table->foreign('invoice_revision_id')->references('id')->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->table('invoice_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_revision_id')->nullable()->after('invoice_id');
            $table->string('discount_type', 16)->default('none')->after('unit_price');
            $table->decimal('discount_value', 19, 4)->default(0)->after('discount_type');
            $table->decimal('line_discount_amount', 19, 4)->default(0)->after('discount_value');
            $table->decimal('invoice_discount_amount', 19, 4)->default(0)->after('line_discount_amount');
            $table->decimal('unit_price_after_discount', 19, 4)->nullable()->after('invoice_discount_amount');
            $table->decimal('line_net_amount', 19, 4)->nullable()->after('unit_price_after_discount');
            $table->decimal('vat_amount', 19, 4)->nullable()->after('line_net_amount');
            $table->decimal('line_total_amount', 19, 4)->nullable()->after('vat_amount');
        });
        DB::connection($connection)->statement(
            'UPDATE invoice_items i JOIN invoice_revisions r ON r.invoice_id = i.invoice_id AND r.revision_number = 1 SET i.invoice_revision_id = r.id',
        );
        $this->calculatePartOneItems($connection);
        Schema::connection($connection)->table('invoice_items', function (Blueprint $table): void {
            $table->dropForeign('invoice_items_invoice_id_foreign');
            $table->dropUnique('invoice_items_position_unique');
            $table->dropColumn('invoice_id');
        });
        DB::connection($connection)->statement(
            'ALTER TABLE `invoice_items` MODIFY `invoice_revision_id` BIGINT UNSIGNED NOT NULL, MODIFY `unit_price_after_discount` DECIMAL(19,4) NOT NULL, MODIFY `line_net_amount` DECIMAL(19,4) NOT NULL, MODIFY `vat_amount` DECIMAL(19,4) NOT NULL, MODIFY `line_total_amount` DECIMAL(19,4) NOT NULL',
        );
        Schema::connection($connection)->table('invoice_items', function (Blueprint $table): void {
            $table->unique(['invoice_revision_id', 'position'], 'invoice_items_position_unique');
            $table->foreign('invoice_revision_id')->references('id')->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    private function createVatSummariesAndOperations(string $connection): void
    {
        Schema::connection($connection)->create('invoice_vat_summaries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_revision_id');
            $table->string('tax_type', 32);
            $table->decimal('percentage', 7, 4)->nullable();
            $table->string('percentage_key', 16);
            $table->decimal('tax_base', 19, 4);
            $table->decimal('vat_amount', 19, 4);
            $table->decimal('total_amount', 19, 4);
            $table->timestamps();
            $table->unique(['invoice_revision_id', 'tax_type', 'percentage_key'], 'invoice_vat_summaries_rate_unique');
            $table->foreign('invoice_revision_id')->references('id')->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });

        Schema::connection($connection)->create('invoice_draft_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_uuid')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_revision_id');
            $table->timestamps();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('invoice_revision_id')->references('id')->on('invoice_revisions')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    private function backfillCalculatedValues(string $connection): void
    {
        $this->calculatePartOneItems($connection);

        DB::connection($connection)->statement(
            "INSERT INTO invoice_vat_summaries
                (uuid, invoice_revision_id, tax_type, percentage, percentage_key, tax_base, vat_amount, total_amount, created_at, updated_at)
             SELECT UUID(), i.invoice_revision_id, v.tax_type, v.percentage, COALESCE(CAST(v.percentage AS CHAR), 'null'),
                    SUM(i.line_net_amount), SUM(i.vat_amount), SUM(i.line_total_amount), MIN(i.created_at), MAX(i.updated_at)
             FROM invoice_items i JOIN invoice_vat_snapshots v ON v.id = i.invoice_vat_snapshot_id
             GROUP BY i.invoice_revision_id, v.tax_type, v.percentage",
        );

        DB::connection($connection)->statement(
            'UPDATE invoice_revisions r SET
                r.subtotal_before_discount = COALESCE((SELECT SUM(ROUND(i.quantity * i.unit_price, 4)) FROM invoice_items i WHERE i.invoice_revision_id = r.id), 0),
                r.discount_total = COALESCE((SELECT SUM(i.line_discount_amount + i.invoice_discount_amount) FROM invoice_items i WHERE i.invoice_revision_id = r.id), 0),
                r.tax_base_total = COALESCE((SELECT SUM(i.line_net_amount) FROM invoice_items i WHERE i.invoice_revision_id = r.id), 0),
                r.vat_total = COALESCE((SELECT SUM(i.vat_amount) FROM invoice_items i WHERE i.invoice_revision_id = r.id), 0),
                r.total_before_rounding = COALESCE((SELECT SUM(i.line_total_amount) FROM invoice_items i WHERE i.invoice_revision_id = r.id), 0)',
        );
        DB::connection($connection)->statement(
            'UPDATE invoice_revisions SET grand_total = CASE WHEN currency = \'CZK\' AND payment_method = \'cash\' THEN ROUND(total_before_rounding, 0) ELSE ROUND(total_before_rounding, 2) END, rounding_adjustment = CASE WHEN currency = \'CZK\' AND payment_method = \'cash\' THEN ROUND(total_before_rounding, 0) ELSE ROUND(total_before_rounding, 2) END - total_before_rounding',
        );
    }

    private function calculatePartOneItems(string $connection): void
    {
        DB::connection($connection)->statement(
            "UPDATE invoice_items i JOIN invoice_vat_snapshots v ON v.id = i.invoice_vat_snapshot_id
             SET i.discount_type = 'none', i.discount_value = 0.0000,
                 i.line_discount_amount = 0.0000, i.invoice_discount_amount = 0.0000,
                 i.unit_price_after_discount = i.unit_price,
                 i.line_net_amount = ROUND(i.quantity * i.unit_price, 4),
                 i.vat_amount = CASE WHEN v.tax_type IN ('standard', 'reduced')
                     THEN ROUND(ROUND(i.quantity * i.unit_price, 4) * v.percentage / 100, 4) ELSE 0.0000 END,
                 i.line_total_amount = ROUND(i.quantity * i.unit_price, 4) + CASE WHEN v.tax_type IN ('standard', 'reduced')
                     THEN ROUND(ROUND(i.quantity * i.unit_price, 4) * v.percentage / 100, 4) ELSE 0.0000 END",
        );
    }

    private function dropSnapshotTriggers(string $connection): void
    {
        foreach (['invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots', 'invoice_vat_snapshots'] as $table) {
            DB::connection($connection)->unprepared("DROP TRIGGER IF EXISTS `{$table}_immutable_update`");
            DB::connection($connection)->unprepared("DROP TRIGGER IF EXISTS `{$table}_immutable_delete`");
        }
    }

    private function createImmutableTriggers(string $connection): void
    {
        foreach ($this->immutableTables as $table) {
            DB::connection($connection)->unprepared(
                "CREATE TRIGGER `{$table}_immutable_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice revision record is immutable'",
            );
            DB::connection($connection)->unprepared(
                "CREATE TRIGGER `{$table}_immutable_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice revision record is immutable'",
            );
        }
    }

    private function dropImmutableTriggers(string $connection): void
    {
        foreach ($this->immutableTables as $table) {
            DB::connection($connection)->unprepared("DROP TRIGGER IF EXISTS `{$table}_immutable_update`");
            DB::connection($connection)->unprepared("DROP TRIGGER IF EXISTS `{$table}_immutable_delete`");
        }
    }

    private function createPartOneSnapshotTriggers(string $connection): void
    {
        foreach (['invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots', 'invoice_vat_snapshots'] as $table) {
            DB::connection($connection)->unprepared("CREATE TRIGGER `{$table}_immutable_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice snapshot is immutable'");
            DB::connection($connection)->unprepared("CREATE TRIGGER `{$table}_immutable_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice snapshot is immutable'");
        }
    }

    private function restorePartOneItems(string $connection): void
    {
        Schema::connection($connection)->table('invoice_items', function (Blueprint $table): void {
            $table->dropForeign(['invoice_revision_id']);
            $table->dropUnique('invoice_items_position_unique');
            $table->unsignedBigInteger('invoice_id')->after('uuid');
            $table->dropColumn([
                'invoice_revision_id', 'discount_type', 'discount_value', 'line_discount_amount',
                'invoice_discount_amount', 'unit_price_after_discount', 'line_net_amount',
                'vat_amount', 'line_total_amount',
            ]);
            $table->unique(['invoice_id', 'position'], 'invoice_items_position_unique');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    private function restorePartOneVatSnapshots(string $connection): void
    {
        Schema::connection($connection)->table('invoice_vat_snapshots', function (Blueprint $table): void {
            $table->dropForeign(['invoice_revision_id']);
            $table->dropUnique('invoice_vat_snapshots_source_unique');
            $table->unsignedBigInteger('invoice_id')->after('uuid');
            $table->dropColumn('invoice_revision_id');
            $table->unique(['invoice_id', 'source_vat_rate_uuid'], 'invoice_vat_snapshots_source_unique');
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    private function restorePartOneSingleSnapshots(string $connection): void
    {
        foreach (['invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots'] as $table) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['invoice_revision_id']);
            });
            DB::connection($connection)->statement("ALTER TABLE `{$table}` DROP PRIMARY KEY");
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('invoice_id')->first();
                $blueprint->dropColumn('invoice_revision_id');
            });
            DB::connection($connection)->statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`invoice_id`)");
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('invoice_id')->references('id')->on('invoices')->restrictOnUpdate()->restrictOnDelete();
            });
        }
    }
};
