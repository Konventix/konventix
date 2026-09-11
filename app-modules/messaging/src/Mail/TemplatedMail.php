<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Konventix\Messaging\Data\AttachmentData;

/**
 * An already-rendered, already brand-wrapped message as a Mailable so it can
 * go through Laravel's normal mail pipeline (queueing, events, testing
 * helpers). `html`/`text` come in pre-rendered — `TemplateRenderer` and
 * `Mail\MailLayout` already did the rendering — so content() only has to
 * hand them to `Content` in the shapes it accepts: a raw HTML string
 * (`htmlString`), and a view name for the text part, since `Content` has no
 * raw-string equivalent for text (`messaging::mail.text` echoes the string
 * passed as `with`).
 */
final class TemplatedMail extends Mailable
{
    public function __construct(
        // Named to avoid colliding with Mailable's own public `$subject`,
        // protected `$html`, and public `$textView` properties — same
        // reasoning as `replyToAddress` below.
        private readonly string $messageSubject,
        private readonly string $htmlBody,
        private readonly string $textBody,
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
            subject: $this->messageSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
            text: 'messaging::mail.text',
            with: ['text' => $this->textBody],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map($this->buildAttachment(...), $this->fileAttachments);
    }

    private function buildAttachment(AttachmentData $attachment): Attachment
    {
        if ($attachment->content !== null) {
            $content = $attachment->content;
            $built = Attachment::fromData(fn (): string => $content, $attachment->as);
        } else {
            $built = Attachment::fromStorageDisk($attachment->disk, $attachment->path)->as($attachment->as);
        }

        return $attachment->mime === null ? $built : $built->withMime($attachment->mime);
    }
}
