<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
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
        // One instance per class, not one per formatted value: the service
        // memoises the operator's chosen formats (review finding 6).
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
    ) {}

    public function handle(string $quotaId, string $brandId): ?WaitlistEntry
    {
        $offered = DB::transaction(function () use ($quotaId, $brandId): ?WaitlistEntry {
            /** @var WaitlistEntry|null $entry */
            // Every level of the nested `whereHas` escapes BrandScope
            // separately (#236 fix round 1): a relation query re-applies the
            // scope on the *related* model, and the admin host hardens it to
            // `WHERE 1=0` (ADR-0029, `.ai/rules/app-modules-src.md`). Written
            // as `registrationType.quotas` it read zero rows for a seat an
            // operator's refund had just freed, and the Action returned
            // early without a word.
            $entry = WaitlistEntry::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('state', WaitlistState::Queued)
                ->whereHas(
                    'registrationType',
                    fn ($query) => $query->withoutGlobalScope(BrandScope::class)
                        ->whereHas(
                            'quotas',
                            fn ($inner) => $inner->withoutGlobalScope(BrandScope::class)->whereKey($quotaId),
                        ),
                )
                ->orderBy('queued_at')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return null;
            }

            // Hold: consume ALL the type's quotas, all-or-nothing — if any
            // is still exhausted the entry stays queued for the next release.
            // The `quotas()` relation escapes BrandScope on its own query for
            // the reason above: escaping it on the type alone would have held
            // *zero* quotas here, passed the capacity check vacuously and
            // promoted an entry without consuming anything (#236).
            // Locked in the relation's own `quotas.id` order (#233).
            $quotas = $entry->registrationType()->withoutGlobalScopes()->firstOrFail()
                ->quotas()->withoutGlobalScope(BrandScope::class)->lockForUpdate()->get();

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
                // The brand's clock (#131/#5): the offer expires at an hour the
                // registrant reads on their own event's time, not the
                // deployment's UTC.
                offerExpiresAt: $this->dates->dateTime(
                    $entry->offer_expires_at,
                    $this->brandTimezone->handle($entry->brand_id),
                ),
            ),
            brandId: $entry->brand_id,
        ));
    }
}
