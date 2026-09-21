<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Models\Admission;
use Konventix\Commerce\Models\Order;
use Konventix\Identity\Models\User;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The HTTP layer of the Phase 1 PoC flow: `GET`/`POST
 * /events/{event_ulid}/register/{type}` (TODO.md "Add the public
 * registration form"). D-REG-08 requires an account on both routes.
 */
it('redirects an unauthenticated visitor away from the form', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    $this->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertRedirect('/login');
});

it('rejects an unauthenticated submission', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    $this->post("/events/{$type->event_id}/register/{$type->id}", [])
        ->assertRedirect('/login');

    expect(Registration::count())->toBe(0);
});

it('renders the registration form for an authenticated visitor', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create(['name' => 'Community Day']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('registration/Register')
            ->where('registrationTypeId', $type->id)
            ->where('eventId', $type->event_id)
            ->where('name', 'Community Day'));
});

it('404s a type that does not belong to the event in the URL', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/events/'.((string) Str::ulid())."/register/{$type->id}")
        ->assertNotFound();
});

it('403s an invite_only type reached without a token', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->inviteOnly()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertForbidden();
});

it('submits a free on_submit registration end to end: confirmed, admissions issued, Commerce untouched', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->settlement(RegistrationSettlement::Free)
        ->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'organizationName' => null,
            'billingRecipientName' => null,
            'billingEmail' => null,
        ])
        // The form is a full page, so a successful submit leaves it for the
        // registrant's own record — never back to `registration.show`.
        ->assertRedirect(route('registration.mine.show', ['registration' => Registration::sole()->id]));

    $registration = Registration::sole();

    expect($registration->registrant_user_id)->toBe($user->id)
        ->and($registration->status->value)->toBe('confirmed');

    // ADR-0005/ADR-0004: the confirmation materialized entitlements in
    // Admission purely from the event payload.
    expect(Admission::where('registration_id', $registration->id)->count())->toBe(1);

    // ADR-0026: a free registration never touches Commerce — no order rows.
    expect(Order::count())->toBe(0);
});

it('validates the submission payload through SubmitRegistrationData', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'billingEmail' => 'not-an-email',
        ])
        ->assertSessionHasErrors('billingEmail');

    expect(Registration::count())->toBe(0);
});

it('leaves visibility other than public unreachable for submission', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->visibility(RegistrationVisibility::Link)->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [])
        ->assertForbidden();

    expect(Registration::count())->toBe(0);
});
