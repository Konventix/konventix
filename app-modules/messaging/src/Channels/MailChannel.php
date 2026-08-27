<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Branding\BrandMailIdentityResolver;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Mail\MailLayout;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Templates\RenderedMessage;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;

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
        private readonly BrandMailIdentityResolver $identityResolver,
        private readonly BrandUrl $brandUrl,
    ) {}

    public function send(SendMessageData $message, RenderedMessage $rendered): void
    {
        $settings = $this->settingsFor($message->brandId);

        $identity = $this->identityResolver->for($message->brandId);
        $wrapped = MailLayout::wrap($rendered, $identity, $this->preferencesUrlFor($message));

        $mailable = new TemplatedMail(
            messageSubject: $rendered->subject,
            htmlBody: $wrapped['html'],
            textBody: $wrapped['text'],
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

    /**
     * A link to manage notification preferences, only for a template that
     * declares a topic (ADR-0048 point 4) — contractual/legal mail carries
     * none, and the suppression list is its only opt-out. Built against the
     * brand's own host when it has one (this runs in a queue job, which has
     * no request to derive a host from); a brand with no host yet — or the
     * route not existing at all — falls back to the application's own root,
     * or to no link.
     */
    private function preferencesUrlFor(SendMessageData $message): ?string
    {
        if ($message->topicKey === null || ! Route::has('notification-preferences.edit')) {
            return null;
        }

        $brand = Brand::query()->find($message->brandId);
        $base = $brand === null ? null : $this->brandUrl->baseFor($brand);

        if ($base === null) {
            return route('notification-preferences.edit', absolute: true);
        }

        return rtrim($base, '/').route('notification-preferences.edit', absolute: false);
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
