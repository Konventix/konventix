<?php

declare(strict_types=1);

namespace Konventix\Messaging\Digests;

use Carbon\CarbonImmutable;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Recipient;
use Konventix\Messaging\Topics\Topic;

/**
 * Decides whether an accepted send is held for coalescing and until when
 * (ADR-0041 point 5). Holding acts on the log's `held` state, never by
 * dropping — the sweep (`messaging:flush-held`) later sends one held
 * message as itself and coalesces several into a digest.
 *
 * A digestable topic's first send opens a 30-minute window per
 * (brand, recipient, channel); further sends inside it join the window by
 * reusing its `hold_until`, which is what makes them one digest. Quiet
 * hours (22:00–08:00 in the recipient's timezone; the brand's Messaging
 * settings, then the application timezone, as fallbacks) push the window's
 * end to 08:00. `urgent` bypasses both (D-CNT-08's word).
 */
final class HoldPolicy
{
    public const WINDOW_MINUTES = 30;

    public const QUIET_START_HOUR = 22;

    public const QUIET_END_HOUR = 8;

    /**
     * The instant this envelope may leave, or null to dispatch immediately.
     */
    public function holdUntil(
        SendMessageRequest $request,
        ?Topic $topic,
        Channel $channel,
        string $brandId,
        string $email,
        ?Recipient $recipient,
    ): ?CarbonImmutable {
        if ($topic === null || ! $topic->digestable || $request->urgent) {
            return null;
        }

        // Attachments are referenced on the envelope but not persisted on
        // the log row, so a held send would lose them at flush time.
        // An attachment-bearing send (an invoice, a badge) is a document
        // delivery, not a nudge — it goes out immediately.
        if ($request->attachments !== null && $request->attachments->count() > 0) {
            return null;
        }

        $openWindow = MessageRecord::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('channel', $channel)
            ->where('status', MessageStatus::Held)
            ->where('hold_until', '>', now())
            ->when(
                $request->recipientId !== null,
                fn ($query) => $query->where('recipient_id', $request->recipientId),
                fn ($query) => $query->whereNull('recipient_id')->where('recipient_email', $email),
            )
            ->max('hold_until');

        if ($openWindow !== null) {
            // Join the window as-is: its end was already quiet-hours
            // adjusted when it opened.
            return CarbonImmutable::parse($openWindow);
        }

        return $this->outsideQuietHours(
            CarbonImmutable::now()->addMinutes(self::WINDOW_MINUTES),
            $this->timezoneFor($brandId, $recipient),
        );
    }

    /**
     * Pushes an instant that falls into quiet hours to their end (08:00
     * local); anything else passes through unchanged.
     */
    private function outsideQuietHours(CarbonImmutable $candidate, string $timezone): CarbonImmutable
    {
        $local = $candidate->setTimezone($timezone);

        if ($local->hour >= self::QUIET_START_HOUR) {
            $local = $local->addDay()->setTime(self::QUIET_END_HOUR, 0);
        } elseif ($local->hour < self::QUIET_END_HOUR) {
            $local = $local->setTime(self::QUIET_END_HOUR, 0);
        } else {
            return $candidate;
        }

        return $local->setTimezone(config('app.timezone'));
    }

    private function timezoneFor(string $brandId, ?Recipient $recipient): string
    {
        if ($recipient?->timezone !== null) {
            return $recipient->timezone;
        }

        return app(ResolveBrandTimezone::class)->handle($brandId);
    }
}
