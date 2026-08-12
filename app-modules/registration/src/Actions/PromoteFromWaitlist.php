<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Registration\Data\WaitlistOfferMailData;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\WaitlistOfferMade;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * FIFO promotion when quota frees up (D-REG-12): the oldest queued entry
 * across every type referencing the released quota gets an offer — its
 * type's quota units are CONSUMED as a hold (so a walk-up submission
 * cannot snipe the spot), the offer expires after 48 hours, and the
 * registrant is told through Messaging with a signed claim link.
 *
 * Runs from the QuotaReleased listener, `withoutBrandScope` scoped to the
 * payload's own brand (ADR-0010) — replays and cross-brand queue workers
 * must not depend on ambient context.
 */
final class PromoteFromWaitlist
{
    public const int OFFER_HOURS = 48;

    private const string TEMPLATE_KEY = 'registration.waitlist-offer';

    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function handle(string $quotaId, string $brandId): ?WaitlistEntry
    {
        $offered = DB::transaction(function () use ($quotaId, $brandId): ?WaitlistEntry {
            /** @var WaitlistEntry|null $entry */
            $entry = WaitlistEntry::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('state', WaitlistState::Queued)
                ->whereHas('registrationType.quotas', fn ($query) => $query->whereKey($quotaId))
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return null;
            }

            // Hold: consume ALL the type's quotas, all-or-nothing — if any
            // is still exhausted the entry stays queued for the next release.
            $quotas = $entry->registrationType()->withoutGlobalScopes()->firstOrFail()
                ->quotas()->lockForUpdate()->get();

            foreach ($quotas as $quota) {
                /** @var Quota $quota */
                if (! $quota->hasCapacityFor(1)) {
                    return null;
                }
            }

            foreach ($quotas as $quota) {
                $quota->consume(1);
            }

            $entry->forceFill([
                'state' => WaitlistState::Offered,
                'offered_at' => now(),
                'offer_expires_at' => now()->addHours(self::OFFER_HOURS),
            ])->save();

            event(new WaitlistOfferMade(
                waitlistEntryId: $entry->id,
                registrationTypeId: $entry->registration_type_id,
                brandId: $entry->brand_id,
                registrantUserId: $entry->registrant_user_id,
                offerExpiresAt: $entry->offer_expires_at->toIso8601String(),
            ));

            return $entry;
        });

        if ($offered !== null) {
            $this->notify($offered);
        }

        return $offered;
    }

    private function notify(WaitlistEntry $entry): void
    {
        try {
            $this->send($entry);
        } catch (\RuntimeException $exception) {
            // A registrant predating the recipient read model has no
            // resolvable address (Messaging TODO: backfill). The offer
            // stands either way — losing the mail must not roll back the
            // hold or crash the queue drain.
            Log::warning('Waitlist offer mail could not be sent.', [
                'waitlist_entry_id' => $entry->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function send(WaitlistEntry $entry): void
    {
        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: $entry->registrant_user_id,
            templateKey: self::TEMPLATE_KEY,
            payload: new WaitlistOfferMailData(
                claimUrl: URL::temporarySignedRoute(
                    'registration.waitlist.claim',
                    $entry->offer_expires_at,
                    ['entry' => $entry->id],
                ),
                offerExpiresAt: $entry->offer_expires_at->format('d.m.Y H:i'),
            ),
            brandId: $entry->brand_id,
        ));
    }
}
