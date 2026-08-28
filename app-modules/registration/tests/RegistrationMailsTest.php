<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\CancelPendingRegistrationForCart;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\RejectRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Models\RegistrationType;

/**
 * ADR-0048 point 3: Registration owns its own lifecycle mail — a registration
 * tells its holder every time its state changes. `RegistrationSettlementRequested`
 * is faked throughout wherever a type would otherwise trigger it (`instant`/
 * `on_payment`, exhibitor `invoice`/`on_approval`): Commerce's own listener
 * on that event needs a seller identity this file has no reason to set up,
 * and none of these tests are about settlement (`SettlementRequestTest.php`
 * already covers that faking pattern for the same reason).
 */
function regMailsRecipient(string $brandId): User
{
    $user = User::factory()->create();

    // Messaging's recipient read model has no factory of its own — rows
    // exist only through `ProjectRecipient`'s projection, so a test that
    // needs `SendMessage` to resolve an address emits the Identity event
    // instead (mirrors `Konventix\Commerce\Tests\orderMailsRecipient`).
    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        locale: $user->locale,
        registeredAt: now()->toIso8601String(),
    ));

    return $user;
}

function regMailsFreeSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(organizationName: null, billingRecipientName: 'Ada Lovelace', billingEmail: 'ada@example.test');
}

function regMailsBilledSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: 'Musterfirma GmbH',
        billingRecipientName: 'Erika Musterfrau',
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: 'Musterstraße 1',
        billingPostalCode: '18439',
        billingCity: 'Stralsund',
        billingCountryCode: 'DE',
    );
}

function regMailsInstantOnPaymentType(): RegistrationType
{
    return RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced(unitAmountNet: '30.00')
        ->create();
}

function regMailsInvoiceNoApprovalType(): RegistrationType
{
    return RegistrationType::factory()
        ->settlement(RegistrationSettlement::Invoice)
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->priced(unitAmountNet: '250.00')
        ->create();
}

/**
 * Asserts the push landed on Messaging's own `messaging` queue — the
 * documented convention (`SendQueuedMessage` never dispatches with
 * `->onQueue(...)` elsewhere, see its own docblock) — before handing back
 * the matching jobs for the caller's own payload assertions.
 *
 * @return array<int, SendQueuedMessage>
 */
function regMailsPushed(string $templateKey): array
{
    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    );

    return Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    )->all();
}

function regMailsNotPushed(string $templateKey): void
{
    Queue::assertNotPushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    );
}

function regMailsRender(string $templateKey, RegistrationMailData $payload, string $locale = 'de'): string
{
    $message = new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: (string) Str::ulid(),
        recipientId: (string) Str::ulid(),
        recipientEmail: 'registrant@example.test',
        recipientLocale: $locale,
        templateKey: $templateKey,
        payload: $payload,
        channel: Channel::Mail->value,
    );

    return app(TemplateRenderer::class)->render($message)->body;
}

it('sends only registration.confirmed, not registration.submitted, when a free type auto-confirms at submission', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Queue::fake();

    $type = RegistrationType::factory()->create();

    $registration = app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsFreeSubmission());

    // registration.confirmed fires in the same request and is already the
    // receipt — a registration.submitted mail here would say nothing new.
    regMailsNotPushed('registration.submitted');

    $confirmed = regMailsPushed('registration.confirmed');
    expect($confirmed)->toHaveCount(1);
    $payload = $confirmed[0]->message->payload->toArray();
    expect($confirmed[0]->message->recipientId)->toBe($registrant->id)
        ->and($payload['registrationTypeName'])->toBe($type->name)
        ->and($payload['holderName'])->toBe('Ada Lovelace')
        ->and(str_contains($payload['registrationUrl'], '/my/registrations/'.$registration->id))->toBeTrue();
});

it('sends registration.submitted with nextStep=approval for a type requiring approval', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Queue::fake();

    $type = RegistrationType::factory()->requiringApproval()->create();

    app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsFreeSubmission());

    $submitted = regMailsPushed('registration.submitted');
    expect($submitted)->toHaveCount(1);
    expect($submitted[0]->message->payload->toArray()['nextStep'])->toBe('approval');

    // Not yet approved: no confirmation mail.
    regMailsNotPushed('registration.confirmed');
});

it('sends registration.submitted with nextStep=pay for an instant on_payment type', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Event::fake([RegistrationSettlementRequested::class]);
    Queue::fake();

    $type = regMailsInstantOnPaymentType();

    app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsBilledSubmission());

    $submitted = regMailsPushed('registration.submitted');
    expect($submitted)->toHaveCount(1);
    expect($submitted[0]->message->payload->toArray()['nextStep'])->toBe('pay');
});

it('sends registration.submitted with nextStep=invoice for an invoice-settled type that needs no approval', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Event::fake([RegistrationSettlementRequested::class]);
    Queue::fake();

    $type = regMailsInvoiceNoApprovalType();

    app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsBilledSubmission());

    $submitted = regMailsPushed('registration.submitted');
    expect($submitted)->toHaveCount(1);
    expect($submitted[0]->message->payload->toArray()['nextStep'])->toBe('invoice');
});

it('sends registration.approved with nextStep=invoice, then registration.confirmed, for the exhibitor profile', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Event::fake([RegistrationSettlementRequested::class]);
    Queue::fake();

    $type = RegistrationType::factory()->invoiceSettled()->create();

    $registration = app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsBilledSubmission());

    expect($submittedNextStep = regMailsPushed('registration.submitted')[0]->message->payload->toArray()['nextStep'])->toBe('approval');

    app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());

    $approved = regMailsPushed('registration.approved');
    expect($approved)->toHaveCount(1)
        ->and($approved[0]->message->recipientId)->toBe($registrant->id)
        ->and($approved[0]->message->payload->toArray()['nextStep'])->toBe('invoice');

    expect(regMailsPushed('registration.confirmed'))->toHaveCount(1);
});

it('sends registration.rejected with the raw reasonCode as reason', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Queue::fake();

    $type = RegistrationType::factory()->requiringApproval()->create();
    $registration = app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsFreeSubmission());

    app(RejectRegistration::class)->handle($registration, (string) Str::ulid(), 'capacity');

    $rejected = regMailsPushed('registration.rejected');
    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->message->recipientId)->toBe($registrant->id)
        ->and($rejected[0]->message->payload->toArray()['reason'])->toBe('capacity');
});

it('sends registration.cancelled with cancelReason=cart_expired when a cart line dies', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Event::fake([RegistrationSettlementRequested::class]);
    Queue::fake();

    $type = regMailsInstantOnPaymentType();
    $registration = app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsBilledSubmission());

    app(CancelPendingRegistrationForCart::class)->handle($brand->id, $registration->id);

    $cancelled = regMailsPushed('registration.cancelled');
    expect($cancelled)->toHaveCount(1);
    $payload = $cancelled[0]->message->payload->toArray();
    expect($payload['cancelReason'])->toBe('cart_expired')
        ->and($payload['wasPaid'])->toBeFalse();
});

it('sends registration.cancelled with cancelReason=registrant when the registrant cancels themselves', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Queue::fake();

    $type = RegistrationType::factory()->create();
    $registration = app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsFreeSubmission());

    app(CancelRegistration::class)->handle($registration, $registrant->id, 'registrant-withdrew');

    $cancelled = regMailsPushed('registration.cancelled');
    expect($cancelled)->toHaveCount(1);
    expect($cancelled[0]->message->payload->toArray()['cancelReason'])->toBe('registrant');
});

it('sends registration.cancelled with cancelReason=operator and wasPaid=true when an organizer cancels a paid registration', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Queue::fake();

    $type = RegistrationType::factory()->create();
    $registration = app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsFreeSubmission());
    $registration->forceFill(['payment_status' => PaymentStatus::Paid])->save();

    app(CancelRegistration::class)->handle($registration, (string) Str::ulid(), 'no-show');

    $cancelled = regMailsPushed('registration.cancelled');
    expect($cancelled)->toHaveCount(1);
    $payload = $cancelled[0]->message->payload->toArray();
    expect($payload['cancelReason'])->toBe('operator')
        ->and($payload['wasPaid'])->toBeTrue();
});

it('logs a warning and still confirms the registration when the registrant has no resolvable address', function (): void {
    $this->withBrand();
    Queue::fake();
    Log::spy();

    $type = RegistrationType::factory()->create();

    // A registrant ULID with no `UserRegistered` behind it — Messaging's
    // recipient read model has nothing to resolve, the same gap
    // `PromoteFromWaitlist::notify()`'s docblock names. The state
    // transition must not roll back or crash for it.
    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), regMailsFreeSubmission());

    expect($registration->status->value)->toBe('confirmed');
    Queue::assertNotPushed(SendQueuedMessage::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be sent')
            && $context['registration_id'] === $registration->id);
});

// --- Body rendering: the branch-free templates plus the one place each
// branches (`@if`/`@elseif` on `nextStep`/`cancelReason`, `match()` on
// `reason`) — one check per template, in both registered locales.

it('greets by holder name when one is known, and plainly when it is not', function (): void {
    $confirmed = fn (?string $holderName): RegistrationMailData => new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        holderName: $holderName,
    );

    expect(regMailsRender('registration.confirmed', $confirmed('Max Mustermann')))
        ->toContain('<p>Guten Tag Max Mustermann,</p>');

    expect(regMailsRender('registration.confirmed', $confirmed(null)))
        ->toContain('<p>Guten Tag,</p>');

    expect(regMailsRender('registration.confirmed', $confirmed('Max Mustermann'), 'en'))
        ->toContain('<p>Hello Max Mustermann,</p>');
});

it('renders the nextStep=pay sentence in registration.submitted, in both locales', function (): void {
    $payload = new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        nextStep: 'pay',
    );

    expect(regMailsRender('registration.submitted', $payload))
        ->toContain('Bitte schließen Sie die Zahlung ab');

    expect(regMailsRender('registration.submitted', $payload, 'en'))
        ->toContain('Please complete the payment');
});

it('renders every nextStep branch in registration.approved', function (string $nextStep, string $expected): void {
    $payload = new RegistrationMailData(
        registrationTypeName: 'Exhibitor Package',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        nextStep: $nextStep,
    );

    expect(regMailsRender('registration.approved', $payload))->toContain($expected);
})->with([
    'approval' => ['approval', 'Wir prüfen Ihre Anmeldung und melden uns.'],
    'pay' => ['pay', 'Bitte schließen Sie die Zahlung ab'],
    'invoice' => ['invoice', 'Sie erhalten eine Rechnung per E-Mail.'],
    'confirmed' => ['confirmed', 'Ihre Anmeldung ist bestätigt.'],
]);

it('maps a known rejection reason and falls back to the raw code for one it does not know, in both locales', function (): void {
    $known = new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        reason: 'capacity',
    );
    $unknown = new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        reason: 'a-code-nobody-mapped-yet',
    );

    expect(regMailsRender('registration.rejected', $known))
        ->toContain('Die Kapazität war ausgeschöpft.');

    expect(regMailsRender('registration.rejected', $known, 'en'))
        ->toContain('Capacity was exhausted.');

    expect(regMailsRender('registration.rejected', $unknown))
        ->toContain('Grund: a-code-nobody-mapped-yet</p>');
});

it('renders the cart_expired cancellation sentence, in both locales', function (): void {
    $payload = new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        cancelReason: 'cart_expired',
    );

    expect(regMailsRender('registration.cancelled', $payload))
        ->toContain('Ihre Reservierung ist abgelaufen, bevor die Zahlung einging.');

    expect(regMailsRender('registration.cancelled', $payload, 'en'))
        ->toContain('Your reservation expired before payment was received.');
});

it('renders the registrant-vs-operator cancellation phrasing and the refund sentence when wasPaid', function (): void {
    $byRegistrant = new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        cancelReason: 'registrant',
    );
    $byOperatorPaid = new RegistrationMailData(
        registrationTypeName: 'Visitor Ticket',
        registrationUrl: 'https://brand.example.test/my/registrations/abc',
        cancelReason: 'operator',
        wasPaid: true,
    );

    expect(regMailsRender('registration.cancelled', $byRegistrant))
        ->toContain('auf Ihren Wunsch')
        ->not->toContain('Gutschrift');

    expect(regMailsRender('registration.cancelled', $byOperatorPaid))
        ->toContain('durch den Veranstalter')
        ->toContain('Gutschrift');
});
