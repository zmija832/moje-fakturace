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

class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $mailSubject,
        public readonly string $bodyText,
        public readonly string $bodyHtml,
        private readonly InvoiceDocument $document,
        private readonly ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(html: 'mail.invoices.issued-html', text: 'mail.invoices.issued-text');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->document->storage_disk, $this->document->storage_path)
                ->as($this->document->original_filename)
                ->withMime($this->document->mime_type),
        ];
    }
}
