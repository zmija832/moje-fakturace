<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Technical lower bound representing an unbounded validity period for the system status. */
    private const VALID_FROM = '1000-01-01';

    private const NAME = 'Neplátce DPH';

    private const CODE = 'NON_PAYER';

    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        $database = DB::connection($connection);

        $this->replaceValuesConstraint($connection, true);

        $rates = $database->table('vat_rates')->where('tax_type', 'non_payer')->get();

        if ($rates->count() > 1) {
            throw new RuntimeException('Business databáze obsahuje více systémových režimů NonPayer.');
        }

        if ($rates->count() === 1) {
            $this->assertSystemRateInvariants($rates->first());

            return;
        }

        $now = now();
        $database->table('vat_rates')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => self::NAME,
            'code' => self::CODE,
            'tax_type' => 'non_payer',
            'percentage' => null,
            'valid_from' => self::VALID_FROM,
            'valid_to' => null,
            'is_active' => true,
            'sort_order' => 0,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        $database = DB::connection($connection);
        $rates = $database->table('vat_rates')->where('tax_type', 'non_payer')->get();

        if ($rates->count() > 1) {
            throw new RuntimeException('Rollback odmítl více systémových režimů NonPayer.');
        }

        if ($rates->count() === 1) {
            $rate = $rates->first();
            $this->assertSystemRateInvariants($rate);

            if ($database->table('vat_rate_defaults')->where('vat_rate_id', $rate->id)->exists()) {
                throw new RuntimeException('Rollback odmítl systémový režim NonPayer použitý jako výchozí sazba.');
            }

            if (
                $database->getSchemaBuilder()->hasTable('invoice_vat_snapshots')
                && $database->table('invoice_vat_snapshots')->where('source_vat_rate_uuid', $rate->uuid)->exists()
            ) {
                throw new RuntimeException('Rollback odmítl systémový režim NonPayer použitý ve snapshotu faktury.');
            }

            $database->table('vat_rates')->where('id', $rate->id)->delete();
        }

        $this->replaceValuesConstraint($connection, false);
    }

    private function replaceValuesConstraint(string $connection, bool $includeNonPayer): void
    {
        $database = DB::connection($connection);
        $serverVersion = strtolower((string) $database->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION));
        $dropClause = str_contains($serverVersion, 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $database->statement(sprintf(
            'ALTER TABLE vat_rates %s vat_rates_values_check',
            $dropClause,
        ));

        $types = ['standard', 'reduced', 'zero', 'exempt', 'reverse_charge', 'out_of_scope'];
        $nullPercentageTypes = ['exempt', 'reverse_charge', 'out_of_scope'];
        if ($includeNonPayer) {
            $types[] = 'non_payer';
            $nullPercentageTypes[] = 'non_payer';
        }

        $typesSql = implode(', ', array_map($database->getPdo()->quote(...), $types));
        $nullPercentageTypesSql = implode(', ', array_map($database->getPdo()->quote(...), $nullPercentageTypes));

        $database->statement(
            'ALTER TABLE vat_rates '
            .'ADD CONSTRAINT vat_rates_values_check '
            .'CHECK ('
            .'tax_type IN ('.$typesSql.') '
            .'AND (valid_to IS NULL OR valid_to >= valid_from) '
            .'AND ('
            .'(tax_type IN (\'standard\', \'reduced\') AND percentage IS NOT NULL AND percentage BETWEEN 0 AND 100) '
            .'OR (tax_type = \'zero\' AND percentage = 0) '
            .'OR (tax_type IN ('.$nullPercentageTypesSql.') AND percentage IS NULL)'
            .'))',
        );
    }

    private function assertSystemRateInvariants(object $rate): void
    {
        if (
            ! Str::isUuid((string) $rate->uuid)
            || $rate->name !== self::NAME
            || $rate->code !== self::CODE
            || $rate->percentage !== null
            || $rate->valid_from !== self::VALID_FROM
            || $rate->valid_to !== null
            || (int) $rate->is_active !== 1
            || $rate->archived_at !== null
        ) {
            throw new RuntimeException('Systémový režim NonPayer porušuje požadované invarianty.');
        }
    }
};
