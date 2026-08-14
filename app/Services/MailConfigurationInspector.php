<?php

namespace App\Services;

final class MailConfigurationInspector
{
    /** @return array{mailer:string,transport:string,from_configured:bool,host_configured:bool,port_configured:bool,username_configured:bool,password_configured:bool,usable:bool} */
    public function inspect(): array
    {
        $mailer = (string) config('mail.default');
        $configuration = config('mail.mailers.'.$mailer);
        $transport = is_array($configuration) ? (string) ($configuration['transport'] ?? '') : '';
        $from = (string) config('mail.from.address');

        return [
            'mailer' => $mailer !== '' ? $mailer : 'nenastaven',
            'transport' => $transport !== '' ? $transport : 'nenastaven',
            'from_configured' => filter_var($from, FILTER_VALIDATE_EMAIL) !== false,
            'host_configured' => $transport !== 'smtp' || trim((string) ($configuration['host'] ?? '')) !== '',
            'port_configured' => $transport !== 'smtp' || (int) ($configuration['port'] ?? 0) > 0,
            'username_configured' => $transport !== 'smtp' || trim((string) ($configuration['username'] ?? '')) !== '',
            'password_configured' => $transport !== 'smtp' || trim((string) ($configuration['password'] ?? '')) !== '',
            'usable' => $this->isDeliveringMailer($mailer),
        ];
    }

    public function isDeliveringMailer(?string $mailer = null, array $visited = []): bool
    {
        $mailer ??= (string) config('mail.default');
        if ($mailer === '' || isset($visited[$mailer])) {
            return false;
        }

        $visited[$mailer] = true;
        $configuration = config('mail.mailers.'.$mailer);
        if (! is_array($configuration)) {
            return false;
        }

        $transport = (string) ($configuration['transport'] ?? '');
        if ($transport === '' || in_array($transport, ['log', 'array'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $configuration['mailers'] ?? [];

            return is_array($mailers) && $mailers !== []
                && collect($mailers)->every(fn (mixed $nested): bool => is_string($nested)
                    && $this->isDeliveringMailer($nested, $visited));
        }
        if ($transport === 'smtp') {
            return trim((string) ($configuration['host'] ?? '')) !== ''
                && (int) ($configuration['port'] ?? 0) > 0
                && trim((string) ($configuration['username'] ?? '')) !== ''
                && trim((string) ($configuration['password'] ?? '')) !== '';
        }

        return true;
    }

    public function isProductionUsable(): bool
    {
        return $this->isDeliveringMailer()
            && filter_var((string) config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }
}
