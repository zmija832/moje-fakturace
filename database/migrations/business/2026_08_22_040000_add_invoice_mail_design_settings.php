<?php

use App\Enums\BusinessConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->table('invoice_email_settings', function (Blueprint $table): void {
            $table->string('template_style', 24)->default('modern')->after('reply_to');
            $table->string('logo_path', 500)->nullable()->after('template_style');
            $table->string('signature_name')->nullable()->after('signature');
            $table->string('signature_role')->nullable()->after('signature_name');
            $table->string('signature_phone', 64)->nullable()->after('signature_role');
            $table->string('signature_email')->nullable()->after('signature_phone');
            $table->string('signature_web', 500)->nullable()->after('signature_email');
            $table->text('signature_text')->nullable()->after('signature_web');
        });
        Schema::connection($connection)->table('invoice_reminders', function (Blueprint $table): void {
            $table->longText('body_html')->nullable()->after('body_text');
        });
        Schema::connection($connection)->table('invoice_paid_notifications', function (Blueprint $table): void {
            $table->longText('body_html')->nullable()->after('body_text');
        });
        Schema::connection($connection)->table('invoice_automation_settings', function (Blueprint $table): void {
            $table->string('partial_subject')->nullable()->after('notify_customer_when_paid');
            $table->text('partial_body')->nullable()->after('partial_subject');
            $table->string('overpaid_subject')->nullable()->after('paid_body');
            $table->text('overpaid_body')->nullable()->after('overpaid_subject');
        });
        DB::connection($connection)->table('invoice_automation_settings')->update([
            'partial_subject' => 'Potvrzení částečné úhrady faktury {invoice_number}',
            'partial_body' => 'Dobrý den, evidujeme platbu ve výši {payment_amount} k faktuře {invoice_number}. Děkujeme.',
            'overpaid_subject' => 'Potvrzení platby k faktuře {invoice_number}',
            'overpaid_body' => 'Dobrý den, evidujeme platbu ve výši {payment_amount} k faktuře {invoice_number}. Faktura je uhrazena a evidujeme přeplatek ve výši {overpayment}.',
        ]);
    }

    public function down(): void
    {
        $connection = BusinessConnection::fromConfiguredValue(DB::getDefaultConnection())->connectionName();
        Schema::connection($connection)->table('invoice_automation_settings', fn (Blueprint $table) => $table->dropColumn(['partial_subject', 'partial_body', 'overpaid_subject', 'overpaid_body']));
        Schema::connection($connection)->table('invoice_paid_notifications', fn (Blueprint $table) => $table->dropColumn('body_html'));
        Schema::connection($connection)->table('invoice_reminders', fn (Blueprint $table) => $table->dropColumn('body_html'));
        Schema::connection($connection)->table('invoice_email_settings', function (Blueprint $table): void {
            $table->dropColumn(['template_style', 'logo_path', 'signature_name', 'signature_role', 'signature_phone', 'signature_email', 'signature_web', 'signature_text']);
        });
    }
};
