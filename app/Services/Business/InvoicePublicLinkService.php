<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePublicLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoicePublicLinkService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function activeForInvoice(Invoice $invoice): ?InvoicePublicLink
    {
        $this->connectionResolver->resolve();

        return InvoicePublicLink::query()->active()->where('invoice_id', $invoice->id)->latest('id')->first();
    }

    public function activeUrlForInvoice(Invoice $invoice): ?string
    {
        $link = $this->activeForInvoice($invoice);

        return $link ? $this->url($link) : null;
    }

    public function url(InvoicePublicLink $link): ?string
    {
        try {
            $token = (string) $link->token_ciphertext;
        } catch (Throwable $exception) {
            Log::warning('Token Webfaktury nelze dešifrovat.', [
                'link_uuid' => $link->uuid,
                'exception_class' => $exception::class,
            ]);

            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1
            || ! hash_equals($link->token_hash, hash('sha256', $token))) {
            return null;
        }

        return route('public-invoices.show', ['token' => $token]);
    }

    public function create(Invoice $invoice): InvoicePublicLink
    {
        return $this->mutate($invoice, false);
    }

    public function regenerate(Invoice $invoice): InvoicePublicLink
    {
        return $this->mutate($invoice, true);
    }

    public function revoke(Invoice $invoice): void
    {
        $connection = $this->connectionResolver->resolve()->connectionName();
        DB::connection($connection)->transaction(function () use ($invoice): void {
            $lockedInvoice = $this->issuedInvoice($invoice);
            $link = InvoicePublicLink::query()->active()
                ->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->first();
            if ($link === null) {
                return;
            }

            $link->forceFill(['revoked_at' => now(), 'revoked_by_actor' => $this->actor()])->save();
            $link->setRelation('invoice', $lockedInvoice);
            $this->auditWriter->write(
                BusinessAuditEvent::InvoicePublicLinkRevoked,
                BusinessAuditableType::InvoicePublicLink,
                $link->uuid,
                null,
                $this->auditSanitizer->snapshot(BusinessAuditableType::InvoicePublicLink, $link),
                ['revoked_at'],
                BusinessAuditableType::Invoice,
                $lockedInvoice->uuid,
            );
        }, 3);
    }

    private function mutate(Invoice $invoice, bool $regenerate): InvoicePublicLink
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($invoice, $regenerate): InvoicePublicLink {
            $lockedInvoice = $this->issuedInvoice($invoice);
            $existing = InvoicePublicLink::query()->active()
                ->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->first();
            if ($existing !== null && ! $regenerate) {
                return $existing;
            }

            if ($existing !== null) {
                $existing->forceFill(['revoked_at' => now(), 'revoked_by_actor' => $this->actor()])->save();
            }

            $token = $this->uniqueToken();
            $link = new InvoicePublicLink;
            $link->forceFill([
                'invoice_id' => $lockedInvoice->id,
                'token_hash' => hash('sha256', $token),
                'token_ciphertext' => $token,
                'created_by_actor' => $this->actor(),
            ])->save();
            $link->setRelation('invoice', $lockedInvoice);
            $event = $existing === null
                ? BusinessAuditEvent::InvoicePublicLinkCreated
                : BusinessAuditEvent::InvoicePublicLinkRegenerated;
            $this->auditWriter->write(
                $event,
                BusinessAuditableType::InvoicePublicLink,
                $link->uuid,
                null,
                $this->auditSanitizer->snapshot(BusinessAuditableType::InvoicePublicLink, $link),
                ['public_link'],
                BusinessAuditableType::Invoice,
                $lockedInvoice->uuid,
                $existing ? ['replaced_link_uuid' => $existing->uuid] : null,
            );

            return $link;
        }, 3);
    }

    private function issuedInvoice(Invoice $invoice): Invoice
    {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== InvoiceStatus::Issued || $locked->issued_revision_id === null) {
            throw ValidationException::withMessages(['invoice' => 'Webfakturu lze vytvořit pouze pro vystavenou fakturu.']);
        }

        return $locked;
    }

    private function uniqueToken(): string
    {
        foreach (range(1, 5) as $_attempt) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            if (! InvoicePublicLink::query()->where('token_hash', hash('sha256', $token))->exists()) {
                return $token;
            }
        }

        throw new \RuntimeException('Nepodařilo se bezpečně vytvořit token Webfaktury.');
    }

    private function actor(): ?string
    {
        $user = auth()->user();

        return $user ? 'central-user:'.$user->getAuthIdentifier() : null;
    }
}
