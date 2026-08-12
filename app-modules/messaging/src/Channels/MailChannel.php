<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Templates\RenderedMessage;

/**
 * Messaging's first channel implementation. Resolves the sending brand's
 * transport and sender identity from the envelope's own `brandId` at send
 * time (ADR-0028) — never from ambient context, so a brand-less worker or
 * operator retry still sends through the right transport.
 *
 * A brand with no `BrandMailSettings` row falls back to the application's
 * default mailer (`config('mail.default')` — Mailpit in dev) rather than
 * failing: a half-configured brand degrades to operator-sent mail instead of
 * silence (ADR-0028).
 *
 * Design choice: builds a runtime-registered, uniquely-named mailer
 * (`config(['mail.mailers.<name>' => $transport])` + `Mail::mailer($name)`)
 * rather than `Mail::build($transport)`. Both are "mailer on the fly" in
 * Laravel's terms and produce the same Symfony transport; this form is used
 * because `Mail::fake()`'s fake manager implements `Factory::mailer()` but
 * not `build()`, so tests can `Mail::fake()` this channel and assert on the
 * mailer name a message was sent through (`TemplatedMail::$mailer`) to prove
 * per-brand transport selection without touching a real transport.
 */
final class MailChannel implements MessageChannel
{
    public function __construct(
        private readonly MailFactory $mail,
    ) {}

    public function send(SendMessageData $message, RenderedMessage $rendered): void
    {
        $settings = $this->settingsFor($message->brandId);

        $mailable = new TemplatedMail(
            rendered: $rendered,
            fromAddress: $settings === null ? (string) config('mail.from.address') : $settings->from_address,
            fromName: $settings === null ? config('mail.from.name') : $settings->from_name,
            replyToAddress: $settings?->reply_to,
            fileAttachments: $message->attachments === null
                ? []
                : array_values($message->attachments->toCollection()->all()),
        );

        $this->mail->mailer($this->resolveMailerName($settings))
            ->to($message->recipientEmail)
            ->send($mailable);
    }

    private function settingsFor(string $brandId): ?BrandMailSettings
    {
        // Keyed by the message's own brand, not ambient context (ADR-0010's
        // named escape hatch): the envelope already knows which brand
        // accepted it, and a job can legitimately run with no brand restored
        // (operator retry, brand-less admin trigger) — the earlier
        // context-guarded version silently fell back to the default mailer
        // in exactly those cases.
        return BrandMailSettings::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->first();
    }

    /**
     * The application's default mailer when unconfigured, otherwise a
     * uniquely-named mailer registered from the brand's stored transport
     * config for the duration of this request/job.
     */
    private function resolveMailerName(?BrandMailSettings $settings): ?string
    {
        $transport = $settings?->transport;

        if ($settings === null || $transport === null || $transport === []) {
            return config('mail.default');
        }

        $mailerName = 'messaging-brand-'.$settings->brand_id;

        config(['mail.mailers.'.$mailerName => $transport]);

        return $mailerName;
    }
}
