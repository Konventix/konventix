<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Templates\RenderedMessage;

/**
 * A already-rendered message wrapped as a Mailable so it can go through
 * Laravel's normal mail pipeline (queueing, events, testing helpers).
 * Content is a raw HTML string (`Content::$htmlString`) rather than a Blade
 * view — TemplateRenderer already did the rendering.
 */
final class TemplatedMail extends Mailable
{
    public function __construct(
        private readonly RenderedMessage $rendered,
        private readonly string $fromAddress,
        private readonly ?string $fromName,
        // Named to avoid colliding with Mailable's own public, read-write
        // `$replyTo` property (an address array built by the fluent
        // `replyTo()` method) — this is a single scalar input to envelope().
        private readonly ?string $replyToAddress,
        /** @var list<AttachmentData> Named to dodge Mailable's own `$attachments`. */
        private readonly array $fileAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $this->replyToAddress === null ? [] : [new Address($this->replyToAddress)],
            subject: $this->rendered->subject,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered->body);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            function (AttachmentData $attachment): Attachment {
                $built = Attachment::fromStorageDisk($attachment->disk, $attachment->path)
                    ->as($attachment->as);

                return $attachment->mime === null ? $built : $built->withMime($attachment->mime);
            },
            $this->fileAttachments,
        );
    }
}
