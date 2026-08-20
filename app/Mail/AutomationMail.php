<?php

namespace App\Mail;

use App\Models\Business\InvoiceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AutomationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $bodyText,
        private readonly string $senderName,
        private readonly ?string $replyToAddress = null,
        private readonly ?InvoiceDocument $document = null,
        public readonly ?string $webInvoiceUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('mail.from.address'), $this->senderName), replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [], subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.automation.html', text: 'mail.automation.text');
    }

    public function attachments(): array
    {
        if ($this->document === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->document->storage_disk, $this->document->storage_path)
                ->as($this->document->original_filename)
                ->withMime($this->document->mime_type),
        ];
    }
}
