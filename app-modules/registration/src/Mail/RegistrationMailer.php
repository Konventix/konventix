<?php

declare(strict_types=1);

namespace Konventix\Registration\Mail;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registration;
use RuntimeException;
use Spatie\LaravelData\Data;

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
    ) {}

    public function send(Registration $registration, string $templateKey, Data $payload): void
    {
        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $registration->registrant_user_id,
                templateKey: $templateKey,
                payload: $payload,
                brandId: $registration->brand_id,
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
     * The deadline names its zone. There is no per-operator timezone in
     * the platform yet, so the value is `config('app.timezone')` — printing
     * "00:30" with no zone would repeat exactly the mistake B5 found on the
     * policy page.
     *
     * @return array{cartUrl: ?string, cartHoldExpiresAt: ?string}
     */
    public function cartFields(Registration $registration): array
    {
        $holdExpiresAt = $registration->cart_hold_expires_at;

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
            'cartHoldExpiresAt' => $holdExpiresAt->format('d.m.Y H:i T'),
        ];
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
}
