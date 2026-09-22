<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\ExpireWaitlistOffers;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\PromoteFromWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Events\WaitlistEntryConverted;
use Konventix\Registration\Events\WaitlistEntryQueued;
use Konventix\Registration\Events\WaitlistOfferExpired;
use Konventix\Registration\Events\WaitlistOfferMade;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

function waitlistSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: 'Ada Lovelace',
        billingEmail: 'ada@example.test',
    );
}

/**
 * A public/free type behind a quota with the given capacity.
 *
 * @return array{0: RegistrationType, 1: Quota}
 */
function typeWithQuota(int $capacity): array
{
    $quota = Quota::factory()->withCapacity($capacity)->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    return [$type, $quota];
}

it('queues the whole submission when a quota is exhausted, once per registrant', function (): void {
    $this->withBrand();
    [$type] = typeWithQuota(0);

    Event::fake([WaitlistEntryQueued::class]);

    $registrantId = (string) Str::ulid();

    $entry = app(JoinWaitlist::class)->handle($type, $registrantId, waitlistSubmission());
    $again = app(JoinWaitlist::class)->handle($type, $registrantId, waitlistSubmission());

    expect($entry->state)->toBe(WaitlistState::Queued)
        ->and($entry->billing_recipient_name)->toBe('Ada Lovelace')
        ->and($again->id)->toBe($entry->id)
        ->and(WaitlistEntry::query()->count())->toBe(1);

    Event::assertDispatchedTimes(WaitlistEntryQueued::class, 1);
});

it('promotes the oldest queued entry with a quota hold when a unit frees up', function (): void {
    $this->withBrand();
    [$type, $quota] = typeWithQuota(1);

    // Fill the quota, then queue two entries FIFO.
    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), waitlistSubmission());

    $first = app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), waitlistSubmission());
    $this->travel(1)->minutes();
    $second = app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), waitlistSubmission());

    Event::fake([WaitlistOfferMade::class]);

    // Cancelling the confirmed registration releases the unit → promotion.
    app(CancelRegistration::class)
        ->handle(Registration::query()->findOrFail($registration->id), (string) Str::ulid(), 'registrant-withdrew');

    expect($first->refresh()->state)->toBe(WaitlistState::Offered)
        ->and($first->offer_expires_at)->not->toBeNull()
        ->and($second->refresh()->state)->toBe(WaitlistState::Queued)
        // The hold: the released unit is consumed again by the offer.
        ->and($quota->refresh()->remaining())->toBe(0);

    Event::assertDispatched(WaitlistOfferMade::class, fn (WaitlistOfferMade $e): bool => $e->waitlistEntryId === $first->id);
});

it('converts a claimed offer into a registration without double-consuming the quota', function (): void {
    $brand = $this->withBrand();
    [$type, $quota] = typeWithQuota(1);

    $user = User::factory()->create();

    $entry = WaitlistEntry::factory()->forType($type)->offered()->create([
        'registrant_user_id' => $user->id,
        'billing_recipient_name' => 'Ada Lovelace',
        'billing_email' => 'ada@example.test',
    ]);
    // Simulate the hold the offer took.
    $quota->consume(1);

    Event::fake([WaitlistEntryConverted::class]);

    $url = URL::temporarySignedRoute('registration.waitlist.claim', $entry->offer_expires_at, ['entry' => $entry->id]);

    $this->actingAs($user)->post($url)->assertRedirect();

    $entry->refresh();
    expect($entry->state)->toBe(WaitlistState::Converted)
        ->and($entry->converted_registration_id)->not->toBeNull()
        ->and($quota->refresh()->remaining())->toBe(0)
        ->and(Registration::query()->whereKey($entry->converted_registration_id)->exists())->toBeTrue();

    Event::assertDispatched(WaitlistEntryConverted::class);
});

it('403s a claim by someone other than the queued registrant', function (): void {
    $this->withBrand();
    [$type] = typeWithQuota(1);

    $entry = WaitlistEntry::factory()->forType($type)->offered()->create();

    $url = URL::temporarySignedRoute('registration.waitlist.claim', $entry->offer_expires_at, ['entry' => $entry->id]);

    $this->actingAs(User::factory()->create())->post($url)->assertForbidden();
});

it('expires lapsed offers, releases the hold, and promotes the next entry', function (): void {
    $this->withBrand();
    [$type, $quota] = typeWithQuota(1);

    $offered = WaitlistEntry::factory()->forType($type)->offered()->create([
        'offer_expires_at' => now()->subMinute(),
    ]);
    $quota->consume(1); // the lapsed offer's hold

    $this->travel(2)->minutes();
    $next = app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), waitlistSubmission());

    Event::fake([WaitlistOfferExpired::class, WaitlistOfferMade::class]);

    $expired = app(ExpireWaitlistOffers::class)->handle();

    expect($expired)->toBe(1)
        ->and($offered->refresh()->state)->toBe(WaitlistState::Expired)
        // The released unit went straight into the next entry's hold.
        ->and($next->refresh()->state)->toBe(WaitlistState::Offered)
        ->and($quota->refresh()->remaining())->toBe(0);

    Event::assertDispatched(WaitlistOfferExpired::class);
    Event::assertDispatched(WaitlistOfferMade::class, fn (WaitlistOfferMade $e): bool => $e->waitlistEntryId === $next->id);
});

it('waitlists through the public form when the quota is full', function (): void {
    $this->withBrand();
    [$type] = typeWithQuota(0);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'billingRecipientName' => 'Ada Lovelace',
        ])
        // No projected event summary, so no landing page to name: the
        // registrant's own list is the fallback — never the form itself.
        ->assertRedirect(route('registration.mine.index'));

    expect(WaitlistEntry::query()->where('registrant_user_id', $user->id)->exists())->toBeTrue()
        ->and(Registration::query()->count())->toBe(0);
});

it('sends a waitlisted registrant to the event landing page, which still shows the offer they queued for', function (): void {
    $this->withBrand();
    [$type] = typeWithQuota(0);
    EventSummary::query()->create([
        'event_id' => $type->event_id,
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => now()->subWeek(),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'billingRecipientName' => 'Ada Lovelace',
        ])
        ->assertRedirect(route('event.show', ['event' => 'wintercon']));

    expect(WaitlistEntry::query()->where('registrant_user_id', $user->id)->exists())->toBeTrue();
});

/**
 * A priced `on_payment` type behind a quota with one free unit, with one
 * registrant already queued on it.
 *
 * `on_payment` is the gate D-0.8.2-2 is about: it requests settlement at
 * *submission*, and a waitlist conversion is a submission made long after
 * the registrant decided to buy.
 *
 * @return array{0: RegistrationType, 1: Quota, 2: User, 3: WaitlistEntry}
 */
function waitlistPricedQueue(
    string $unitAmountNet = '30.00',
    TaxClass $taxClass = TaxClass::Standard,
    PriceDisplay $display = PriceDisplay::Gross,
): array {
    $quota = Quota::factory()->withCapacity(1)->create();
    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced(unitAmountNet: $unitAmountNet, taxClass: $taxClass, display: $display)
        ->create();
    $type->quotas()->attach($quota);

    $user = User::factory()->create();

    return [$type, $quota, $user, app(JoinWaitlist::class)->handle($type, (string) $user->id, waitlistSubmission())];
}

/**
 * Promote the queue's head and let the registrant claim the offer, which is
 * the conversion `SubmitRegistration(fromWaitlist:)` performs.
 */
function waitlistPromoteAndClaim(WaitlistEntry $entry, User $user, string $brandId): void
{
    app(PromoteFromWaitlist::class)->handle($entry->registrationType()->firstOrFail()->quotas()->first()->id, $brandId);

    $entry->refresh();

    test()->actingAs($user)->post(URL::temporarySignedRoute(
        'registration.waitlist.claim',
        $entry->offer_expires_at,
        ['entry' => $entry->id],
    ))->assertRedirect();
}

it('pins the price declaration onto the entry when the registrant joins the queue', function (): void {
    $this->withBrand();
    [, , , $entry] = waitlistPricedQueue('30.00');

    expect($entry->price_declaration)->not->toBeNull()
        ->and($entry->price_declaration->unitAmountNet)->toBe('30.00')
        ->and($entry->price_declaration->taxClass)->toBe(TaxClass::Standard)
        ->and($entry->price_declaration->display)->toBe(PriceDisplay::Gross);
});

it('settles a promoted registration at the submitted price after a price rise', function (): void {
    $brand = $this->withBrand();
    [$type, , $user, $entry] = waitlistPricedQueue('30.00');

    // The operator raises the price while the registrant waits. Written
    // straight onto the model rather than through `UpdateRegistrationType`:
    // the test is about what the *queue* remembers, not about the console
    // path that changed it.
    $type->forceFill(['price_declaration' => new PriceDeclarationData('45.00', TaxClass::Standard, PriceDisplay::Gross)])->save();

    Event::fake([RegistrationSettlementRequested::class]);

    waitlistPromoteAndClaim($entry, $user, $brand->id);

    Event::assertDispatched(RegistrationSettlementRequested::class, function (RegistrationSettlementRequested $event): bool {
        expect($event->lines[0]['unit_amount_net'])->toBe('30.00');

        return true;
    });
});

it('settles a promoted registration at the submitted price after a price cut', function (): void {
    $brand = $this->withBrand();
    [$type, , $user, $entry] = waitlistPricedQueue('30.00');

    // The cut cuts both ways: the promise is the promise, and a registrant
    // who queued at 30,00 does not get today's 20,00 either. Repricing a
    // promoted spot downwards is a discount decision nobody took.
    $type->forceFill(['price_declaration' => new PriceDeclarationData('20.00', TaxClass::Standard, PriceDisplay::Gross)])->save();

    Event::fake([RegistrationSettlementRequested::class]);

    waitlistPromoteAndClaim($entry, $user, $brand->id);

    Event::assertDispatched(RegistrationSettlementRequested::class, function (RegistrationSettlementRequested $event): bool {
        expect($event->lines[0]['unit_amount_net'])->toBe('30.00');

        return true;
    });
});

it('pins the tax class and the display flag with the amount, not just the amount', function (): void {
    $brand = $this->withBrand();
    [$type, , $user, $entry] = waitlistPricedQueue('30.00', TaxClass::Standard, PriceDisplay::Gross);

    // The whole declaration moves under the queued registrant: class and
    // display too. Pinning the amount alone would charge a gross the
    // registrant never saw (30,00 + 7% instead of 30,00 + 19%), which is the
    // failure D-0.8.2-2 exists to prevent, reached from the other side.
    //
    // Nothing here freezes tax *law*: `TaxClass` is a class, never a rate,
    // and Commerce resolves both the rate and the treatment (§19, reverse
    // charge) at settlement — see `RequestSettlement`'s docblock.
    $type->forceFill(['price_declaration' => new PriceDeclarationData('30.00', TaxClass::Reduced, PriceDisplay::Net)])->save();

    Event::fake([RegistrationSettlementRequested::class]);

    waitlistPromoteAndClaim($entry, $user, $brand->id);

    Event::assertDispatched(RegistrationSettlementRequested::class, function (RegistrationSettlementRequested $event): bool {
        expect($event->lines[0]['tax_class'])->toBe('standard')
            ->and($event->lines[0]['unit_amount_net'])->toBe('30.00')
            ->and($event->priceDisplay)->toBe('gross');

        return true;
    });
});

it('falls back to the type for an entry queued before the price was pinned', function (): void {
    $brand = $this->withBrand();
    [, , $user, $entry] = waitlistPricedQueue('30.00');

    // The rows that predate the column: null pin, and the behaviour is
    // exactly what it was before this existed — the type's price today.
    $entry->forceFill(['price_declaration' => null])->save();

    Event::fake([RegistrationSettlementRequested::class]);

    waitlistPromoteAndClaim($entry, $user, $brand->id);

    Event::assertDispatched(RegistrationSettlementRequested::class, function (RegistrationSettlementRequested $event): bool {
        expect($event->lines[0]['unit_amount_net'])->toBe('30.00');

        return true;
    });
});

it('schedules the offer sweep every fifteen minutes and never overlapping itself', function (): void {
    // Mirrors `ExpireRegistrationOrdersTest`'s schedule assertion for
    // `commerce:expire-carts`'s cadence, plus the guard #8 asks for: two
    // sweeps over the same lapsed offer would release its held unit twice,
    // because `ExpireWaitlistOffers` reads its rows outside the per-entry
    // transaction.
    $entries = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'registration:expire-waitlist-offers'));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->expression)->toBe('*/15 * * * *')
        ->and($entries->first()->withoutOverlapping)->toBeTrue();
});
