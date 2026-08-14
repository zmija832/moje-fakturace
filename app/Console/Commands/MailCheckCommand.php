<?php

namespace App\Console\Commands;

use App\Services\MailConfigurationInspector;
use Illuminate\Console\Command;

class MailCheckCommand extends Command
{
    protected $signature = 'app:mail-check';

    protected $description = 'Bezpečně ověří produkční použitelnost mail transportu bez odeslání zprávy';

    public function handle(MailConfigurationInspector $inspector): int
    {
        $report = $inspector->inspect();
        $this->table(['Položka', 'Stav'], [
            ['Mailer', $report['mailer']],
            ['Transport', $report['transport']],
            ['From adresa', $report['from_configured'] ? 'nastavena' : 'chybí nebo je neplatná'],
            ['SMTP host', $report['host_configured'] ? 'nastaven' : 'chybí'],
            ['SMTP port', $report['port_configured'] ? 'nastaven' : 'chybí'],
            ['SMTP uživatel', $report['username_configured'] ? 'nastaven' : 'chybí'],
            ['Heslo', $report['password_configured'] ? 'nastaveno (skryto)' : 'chybí'],
            ['Stav', $report['usable'] && $report['from_configured'] ? 'produkčně použitelný' : 'produkčně nepoužitelný'],
        ]);

        return $report['usable'] && $report['from_configured'] ? self::SUCCESS : self::FAILURE;
    }
}
