<?php

declare(strict_types=1);

namespace Konventix\Registration\Mail;

use Illuminate\Support\Facades\Log;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;
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

    private function registrationUrl(Registration $registration): string
    {
        $brand = Brand::query()->find($registration->brand_id);
        $base = $brand === null ? null : $this->brandUrl->baseFor($brand);

        return $base === null
            ? route('registration.mine.show', ['registration' => $registration->id], absolute: true)
            : rtrim($base, '/').route('registration.mine.show', ['registration' => $registration->id], absolute: false);
    }
}
