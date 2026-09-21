<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\ExpireWaitlistOffers;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\WaitlistState;
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
