<?php

namespace App\Listeners;

use App\Models\LoginAudit;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

class AuthenticationAuditSubscriber
{
    public function __construct(private readonly Request $request) {}

    public function handleLogin(Login $event): void
    {
        $this->record('login', $event->user instanceof User ? $event->user : null);
    }

    public function handleFailed(Failed $event): void
    {
        $email = is_string($event->credentials['email'] ?? null)
            ? mb_strtolower($event->credentials['email'])
            : null;

        $this->record(
            event: 'failed',
            user: $event->user instanceof User ? $event->user : null,
            attemptedEmailHash: $email
                ? hash_hmac('sha256', $email, (string) config('app.key'))
                : null,
        );
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', $event->user instanceof User ? $event->user : null);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
        ];
    }

    private function record(string $event, ?User $user, ?string $attemptedEmailHash = null): void
    {
        LoginAudit::query()->create([
            'user_id' => $user?->id,
            'event' => $event,
            'attempted_email_hash' => $attemptedEmailHash,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'occurred_at' => now(),
        ]);
    }
}
