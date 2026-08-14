<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MailCheckCommandTest extends TestCase
{
    public function test_mail_check_reports_usable_smtp_without_exposing_secrets_or_sending(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'fakturace@example.test',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'mailer@example.test',
                'password' => 'top-secret-password',
            ],
        ]);

        $this->assertSame(0, Artisan::call('app:mail-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('produkčně použitelný', $output);
        $this->assertStringContainsString('nastaveno (skryto)', $output);
        $this->assertStringNotContainsString('top-secret-password', $output);
        $this->assertStringNotContainsString('mailer@example.test', $output);
    }

    public function test_mail_check_fails_closed_for_log_or_incomplete_smtp_transport(): void
    {
        config(['mail.default' => 'log', 'mail.from.address' => 'fakturace@example.test']);
        $this->assertSame(1, Artisan::call('app:mail-check'));
        $this->assertStringContainsString('produkčně nepoužitelný', Artisan::output());

        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'fakturace@example.test',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => '',
                'port' => 0,
                'username' => '',
                'password' => '',
            ],
        ]);
        $this->assertSame(1, Artisan::call('app:mail-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('chybí', $output);
        $this->assertStringNotContainsString('MAIL_PASSWORD', $output);
    }
}
