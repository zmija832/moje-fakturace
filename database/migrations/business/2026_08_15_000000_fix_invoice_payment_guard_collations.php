<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_payments')) {
            $this->replaceGuard($connection, true);
        }
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        if (Schema::connection($connection)->hasTable('invoice_payments')) {
            $this->replaceGuard($connection, false);
        }
    }

    private function replaceGuard(string $connection, bool $binarySafe): void
    {
        DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS invoice_payments_insert_guard');

        $statusComparison = $binarySafe ? "BINARY invoice_status <> BINARY 'issued'" : "invoice_status <> 'issued'";
        $currencyComparison = $binarySafe ? 'BINARY invoice_currency <> BINARY NEW.currency' : 'invoice_currency <> NEW.currency';
        $originalTypeComparison = $binarySafe ? "BINARY original_type <> BINARY 'payment'" : "original_type <> 'payment'";
        $originalCurrencyComparison = $binarySafe ? 'BINARY original_currency <> BINARY NEW.currency' : 'original_currency <> NEW.currency';

        DB::connection($connection)->unprepared(<<<SQL
CREATE TRIGGER invoice_payments_insert_guard BEFORE INSERT ON invoice_payments FOR EACH ROW
BEGIN
    DECLARE invoice_status VARCHAR(16);
    DECLARE invoice_currency CHAR(3);
    DECLARE original_type VARCHAR(16);
    DECLARE original_currency CHAR(3);
    DECLARE original_amount DECIMAL(19,4);
    DECLARE reversed_amount DECIMAL(19,4);
    DECLARE ledger_total DECIMAL(19,4);

    SELECT status, currency INTO invoice_status, invoice_currency FROM invoices WHERE id = NEW.invoice_id;
    IF invoice_status IS NULL OR {$statusComparison} THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment requires issued invoice';
    END IF;
    IF {$currencyComparison} THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice payment currency mismatch';
    END IF;

    SELECT COALESCE(SUM(CASE WHEN payment_type = 'payment' THEN amount ELSE -amount END), 0)
        INTO ledger_total FROM invoice_payments WHERE invoice_id = NEW.invoice_id;

    IF NEW.payment_type = 'reversal' THEN
        SELECT payment_type, currency, amount INTO original_type, original_currency, original_amount
            FROM invoice_payments WHERE id = NEW.reverses_payment_id AND invoice_id = NEW.invoice_id;
        IF original_type IS NULL OR {$originalTypeComparison} OR {$originalCurrencyComparison} THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice reversal must reference original payment';
        END IF;
        SELECT COALESCE(SUM(amount), 0) INTO reversed_amount
            FROM invoice_payments WHERE reverses_payment_id = NEW.reverses_payment_id;
        IF reversed_amount + NEW.amount > original_amount OR ledger_total - NEW.amount < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice reversal exceeds paid amount';
        END IF;
    END IF;
END
SQL);
    }
};
