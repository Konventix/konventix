<?php

declare(strict_types=1);

use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Identity\Models\User;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * A4 (#210): with `commerce:shop` on, an `instant` settlement lands in the
 * registrant's cart instead of an order (ADR-0047 point 3) — and the person
 * who just submitted must be taken to the place the money is paid, whatever
 * the confirmation gate says (ADR-0005). With the shop off (the per-brand
 * default) the same request becomes a direct order, and the place is its
 * own pay page (`commerce.pay.show`, ADR-0039) — the 2026-09-21 bugfix
 * round: `store()` used to answer with the registration form itself, so
 * the payer never saw that page.
 *
 * The gate does not decide *where* the money is paid; it decides *when*
 * the settlement request goes out, and therefore whether a cart line or an
 * order exists yet at the moment `store()` answers. When nothing is payable
 * yet, the registrant's own record is the page that says so — never the
 * form they just submitted. This test file pins that per-gate rule end to
 * end.
 */
function instantHandoffType(ConfirmationGate $gate, bool $approvalRequired = false): RegistrationType
{
    $factory = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate($gate)
        ->priced(unitAmountNet: '30.00');

    return ($approvalRequired ? $factory->requiringApproval() : $factory)->create();
}

/**
 * @return array<string, string|null>
 */
function instantHandoffForm(): array
{
    return [
        'organizationName' => null,
        'billingRecipientName' => 'Ada Lovelace',
        'billingEmail' => 'ada@example.test',
    ];
}

function instantHandoffRegistrationLines(): int
{
    return CartLine::query()->where('kind', CartLineKind::Registration)->count();
}

it('sends an on_payment registrant to the cart, where its settlement request already went', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $type = instantHandoffType(ConfirmationGate::OnPayment);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect('/cart');

    expect(instantHandoffRegistrationLines())->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});

it('sends an on_submit registrant to the cart, because confirming at submission already settled into it', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $type = instantHandoffType(ConfirmationGate::OnSubmit);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect('/cart');

    expect(instantHandoffRegistrationLines())->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});

it('sends an on_approval registrant to their own registration, since nothing is payable before approval', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $type = instantHandoffType(ConfirmationGate::OnApproval);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect(route('registration.mine.show', ['registration' => Registration::sole()->id]));

    expect(instantHandoffRegistrationLines())->toBe(0);
});

it('sends an on_submit registrant whose type still needs approval to their own registration', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $type = instantHandoffType(ConfirmationGate::OnSubmit, approvalRequired: true);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect(route('registration.mine.show', ['registration' => Registration::sole()->id]));

    expect(instantHandoffRegistrationLines())->toBe(0);
});

it('sends the shop-off on_payment registrant to the pay page of the direct order the settlement request became', function (): void {
    $this->withBrand();
    $type = instantHandoffType(ConfirmationGate::OnPayment);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect('/pay/registration/'.Registration::sole()->id);

    expect(instantHandoffRegistrationLines())->toBe(0)
        ->and(Order::query()->where('settles_type', 'registration')->count())->toBe(1);
});

it('sends the shop-off on_submit registrant to the pay page too, because confirming at submission already ordered', function (): void {
    $this->withBrand();
    $type = instantHandoffType(ConfirmationGate::OnSubmit);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect('/pay/registration/'.Registration::sole()->id);

    expect(instantHandoffRegistrationLines())->toBe(0)
        ->and(Order::query()->where('settles_type', 'registration')->count())->toBe(1);
});

it('keeps the shop-off on_approval registrant on their own registration, where no order exists yet', function (): void {
    $this->withBrand();
    $type = instantHandoffType(ConfirmationGate::OnApproval);

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", instantHandoffForm())
        ->assertRedirect(route('registration.mine.show', ['registration' => Registration::sole()->id]));

    expect(instantHandoffRegistrationLines())->toBe(0)
        ->and(Order::query()->count())->toBe(0);
});

it('leaves the invoice path on its direct order and sends the registrant to their own registration, even with the shop feature on', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Invoice)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced(unitAmountNet: '30.00')
        ->create();

    $this->actingAs(User::factory()->create())
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'organizationName' => 'Analytical Engines Ltd',
            'billingRecipientName' => 'Ada Lovelace',
            'billingEmail' => 'ada@example.test',
            'billingAddressLine' => 'Baker Street 1',
            'billingPostalCode' => '10115',
            'billingCity' => 'Berlin',
            'billingCountryCode' => 'DE',
        ])
        // An invoice is paid against the invoice, not on the hosted pay
        // page (ADR-0039 is `instant`'s): the record, whose Commerce slot
        // shows the invoice once it is issued, is the right landing.
        ->assertRedirect(route('registration.mine.show', ['registration' => Registration::sole()->id]));

    expect(instantHandoffRegistrationLines())->toBe(0)
        ->and(Order::query()->where('settles_type', 'registration')->count())->toBe(1);
});
