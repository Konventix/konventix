<?php

declare(strict_types=1);

namespace Konventix\Registration\Mail;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Calendar\CalendarFile;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registration;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * ADR-0048 point 3: the module that owns an event owns its mail — every
 * `MailOnRegistration*` listener calls this shared wrapper instead of
 * `SendMessage` directly, the same try/`Log::warning` shape
 * `PromoteFromWaitlist::notify()` established: a mail that cannot be
 * delivered (no resolvable address in Messaging's recipient read model)
 * must not roll back or crash the state transition that triggered it.
 *
 * `registrant_user_id` is required, never nullable (D-REG-08), so unlike
 * Commerce's order mails there is no "no payer" case to guard against
 * before calling `send()`.
 */
final class RegistrationMailer
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly BrandUrl $brandUrl,
        // One instance per class, not one per formatted value: the service
        // memoises the operator's chosen formats (review finding 6).
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
        private readonly MediaLibrary $media,
    ) {}

    /**
     * @param  DataCollection<int, AttachmentData>|null  $attachments
     */
    public function send(Registration $registration, string $templateKey, Data $payload, ?DataCollection $attachments = null): void
    {
        $hero = $this->heroFor($registration);

        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $registration->registrant_user_id,
                templateKey: $templateKey,
                payload: $payload,
                brandId: $registration->brand_id,
                attachments: $attachments,
                heroImageUrl: $hero['url'] ?? null,
                heroImageAlt: $hero['altText'] ?? null,
            ));
        } catch (RuntimeException $exception) {
            Log::warning('Registration lifecycle mail could not be sent.', [
                'registration_id' => $registration->id,
                'template_key' => $templateKey,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The event's banner as the mail's hero row (#292): the media ULID from
     * this module's own event summary, resolved through Platform's library
     * — null without a banner, and null again once the picture is gone, so
     * a deleted file never costs a mail. Same lookup for every lifecycle
     * mail, because the person reading a cancellation deserves the same
     * picture as the person reading a confirmation.
     *
     * @return array{url: string, altText: string|null}|null
     */
    private function heroFor(Registration $registration): ?array
    {
        $bannerMediaId = EventSummary::withoutBrandScope()
            ->where('brand_id', $registration->brand_id)
            ->where('event_id', $registration->event_id)
            ->value('banner_media_id');

        if ($bannerMediaId === null) {
            return null;
        }

        $reference = $this->media->referenceFor($bannerMediaId);

        if ($reference === null) {
            return null;
        }

        return [
            'url' => $reference['url'],
            'altText' => $reference['altText'] ?? $this->eventNameFor($registration),
        ];
    }

    private function eventNameFor(Registration $registration): ?string
    {
        return EventSummary::withoutBrandScope()
            ->where('brand_id', $registration->brand_id)
            ->where('event_id', $registration->event_id)
            ->value('name');
    }

    /**
     * The fields every lifecycle mail carries regardless of which event
     * triggered it, pulled out here so the five listeners differ only in
     * what they add on top (`nextStep`, `reason`, `cancelReason`, `wasPaid`).
     *
     * @return array{registrationTypeName: string, registrationUrl: string, eventName: ?string, holderName: ?string}
     */
    public function commonFields(Registration $registration): array
    {
        return [
            'registrationTypeName' => $registration->registrationType->name,
            'registrationUrl' => $this->registrationUrl($registration),
            'eventName' => EventSummary::withoutBrandScope()
                ->where('brand_id', $registration->brand_id)
                ->where('event_id', $registration->event_id)
                ->value('name'),
            // Organizations register too (ADR-0005) — a registrant with no
            // named contact still gets a name to greet, and a purely
            // personal registration falls through to the plain "Guten Tag,".
            'holderName' => $registration->billing_recipient_name ?? $registration->organization_name,
        ];
    }

    /**
     * Where the money is waiting, when it is waiting in the registrant's
     * cart rather than in an order (#210).
     *
     * With `commerce:shop` on, an `instant` settlement becomes a cart line
     * (ADR-0047 point 3), and on every gate but `on_payment` the registrant
     * is not standing in the shop when that happens: this mail is the only
     * push they get, and without these two fields it points at the
     * registration page and says nothing about a reservation running out.
     *
     * Registration does not read `cart_lines` to know this (ADR-0004) —
     * `cart_hold_expires_at` is stamped by `RecordCartHoldOnLinePlaced`
     * from Commerce's `RegistrationLinePlacedInCart`.
     *
     * Two states say there is nothing left to pay, and the payment is the
     * decisive one. On an `on_payment` gate the registrant pays the cart
     * line *within* its hold and the payment is what confirms, so the stamp
     * is still in the future at the moment this mail is composed — reading
     * the clock alone told someone who had just paid to go and pay in the
     * cart. `RecordOrderPaidForRegistration` clears the stamp as well, so
     * neither half depends on the other being remembered.
     *
     * `Route::has()` for the same reason `RegistrationController::store()`
     * guards: Commerce's routes are behind the shop feature, and a mail
     * must not fail to render because a brand has it off.
     *
     * The deadline is rendered on the brand's own clock and names the zone
     * it is on (`ResolveBrandTimezone`, #131/#5) — printing "00:30" with no
     * zone, or on the deployment's UTC, would repeat exactly the mistake B5
     * found on the policy page.
     *
     * @return array{cartUrl: ?string, cartHoldExpiresAt: ?string}
     */
    public function cartFields(Registration $registration): array
    {
        $holdExpiresAt = $registration->cart_hold_expires_at;
        $zone = $this->brandTimezone->handle($registration->brand_id);

        if (
            $registration->payment_status === PaymentStatus::Paid
            || $holdExpiresAt === null
            || $holdExpiresAt->isPast()
            || ! Route::has('commerce.cart.show')
        ) {
            return ['cartUrl' => null, 'cartHoldExpiresAt' => null];
        }

        return [
            'cartUrl' => $this->brandAbsolute($registration, route('commerce.cart.show', absolute: false)),
            'cartHoldExpiresAt' => $this->dates->dateTime($holdExpiresAt, $zone).' '.$holdExpiresAt->copy()->setTimezone($zone)->format('T'),
        ];
    }

    /**
     * The event's calendar file (spec §6.3, §13's 2026-09-11 amendment),
     * attached to the confirmation mail only — `MailOnRegistrationConfirmed`
     * is the one caller. Built from this module's own `EventSummary` read
     * model (ADR-0004: Registration may not import Event) through the
     * shared encoder every module reaches the way they reach
     * `MediaLibrary`: `CalendarFile::forEvent()`.
     *
     * Null when Registration has not projected a summary for this event,
     * or that summary predates the columns this needs — a confirmation
     * mail with no calendar file is the existing behaviour, not a new
     * failure mode, the same shape as `SendInvoiceMail`'s missing-document
     * guard.
     *
     * `description` is always null here: `EventSummary` carries no
     * description column, unlike `EventLandingController::calendar()`'s
     * fuller call, which reads `Event::$description` directly.
     * `location` is `venue_label` as projected — name and city only, what
     * `VenueAssigned`'s contract carries (§13's venue amendment) — narrower
     * than the public route's full address.
     */
    public function calendarAttachment(Registration $registration): ?AttachmentData
    {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $registration->brand_id)
            ->where('event_id', $registration->event_id)
            ->first();

        if ($summary === null || $summary->starts_at === null || $summary->ends_at === null || $summary->timezone === null) {
            return null;
        }

        $brand = Brand::query()->find($registration->brand_id);

        $ics = CalendarFile::forEvent(
            uid: $summary->event_id.'@'.$this->hostFor($brand),
            summary: $summary->name,
            description: null,
            location: $summary->venue_label,
            url: $brand === null || ! Route::has('event.show')
                ? null
                : $this->brandAbsolute($registration, route('event.show', ['event' => $summary->slug], absolute: false)),
            startsAt: $this->wallClockToInstant($summary->starts_at, $summary->timezone),
            endsAt: $this->wallClockToInstant($summary->ends_at, $summary->timezone),
            lastModifiedAt: $summary->updated_at,
        );

        return new AttachmentData(as: $summary->slug.'.ics', content: $ics, mime: 'text/calendar');
    }

    private function registrationUrl(Registration $registration): string
    {
        return $this->brandAbsolute(
            $registration,
            route('registration.mine.show', ['registration' => $registration->id], absolute: false),
        );
    }

    /**
     * A path on the brand's own host. Mail carries no request, so the
     * ambient host is whatever the queue worker was started with — the
     * brand's base URL is the only correct prefix, and `route(absolute:
     * true)` is the fallback for a brand that has none configured.
     */
    private function brandAbsolute(Registration $registration, string $path): string
    {
        $brand = Brand::query()->find($registration->brand_id);
        $base = $brand === null ? null : $this->brandUrl->baseFor($brand);

        return $base === null
            ? url($path)
            : rtrim($base, '/').$path;
    }

    /**
     * Mirrors `Event::wallClockToInstant()` — a private helper on Event's
     * own model this module may not call (ADR-0004) — on a local copy of
     * the columns: `EventSummary`'s `starts_at`/`ends_at` are wall clock
     * plus an IANA zone (ADR-0027), projected verbatim from the same
     * `EventCreated`/`EventRescheduled` payload fields Event itself
     * stores.
     *
     * `$wallClock` is typed `DateTimeInterface`, not `Carbon`: the
     * application's date class is `CarbonImmutable`
     * (`AppServiceProvider::boot()`'s `Date::use()`), so an Eloquent
     * `datetime` cast — `EventSummary::$starts_at`/`$ends_at` — hands back
     * a `CarbonImmutable`, which does not extend `Illuminate\Support\Carbon`.
     */
    private function wallClockToInstant(DateTimeInterface $wallClock, string $timezone): Carbon
    {
        return Carbon::parse($wallClock->format('Y-m-d H:i:s'), $timezone);
    }

    /**
     * `CalendarFile::forEvent()`'s `UID` is the event id plus the brand's
     * host (spec §6.1) — the same primary-domain reasoning
     * `EventLandingController::hostFor()` uses for the public route, so
     * the two surfaces agree on one host for the same brand and a calendar
     * app re-importing either file updates the same entry.
     */
    private function hostFor(?Brand $brand): string
    {
        $origin = $brand === null ? null : $this->brandUrl->baseFor($brand);

        return $origin === null
            ? (string) parse_url((string) config('app.url'), PHP_URL_HOST)
            : (string) parse_url($origin, PHP_URL_HOST);
    }
}
