<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\CheckIn;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;

/**
 * The ticket manager's routes (#292 §4.3): holder and invite, both keyed on
 * `admissions.buyer_user_id` and nothing else. A stranger — including the
 * *holder*, who did not buy it — gets the 403 the ticket download gives.
 */
function holderRoutesPlaceholder(User $buyer, string $eventId): Admission
{
    ticketEventSummary(test()->withBrand(), $eventId);

    return Admission::factory()->forRegistration((string) Str::ulid())->create([
        'event_id' => $eventId,
        'holder_user_id' => null,
        'state' => AdmissionState::Issued,
        'buyer_user_id' => $buyer->id,
    ]);
}

it('lets the buyer claim a ticket for themselves and take it back', function (): void {
    $buyer = User::factory()->create();
    $admission = holderRoutesPlaceholder($buyer, (string) Str::ulid());

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->post('/my/admissions/'.$admission->id.'/holder')
        ->assertRedirect('/my/registrations/'.$admission->registration_id);

    expect($admission->fresh()->holder_user_id)->toBe($buyer->id);

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->delete('/my/admissions/'.$admission->id.'/holder')
        ->assertRedirect();

    expect($admission->fresh()->holder_user_id)->toBeNull();
});

it('turns a refused holder change into a form error rather than a crash', function (): void {
    $buyer = User::factory()->create();
    $admission = holderRoutesPlaceholder($buyer, (string) Str::ulid());
    $admission->forceFill(['holder_user_id' => (string) Str::ulid(), 'state' => AdmissionState::Assigned])->save();
    CheckIn::factory()->create(['admission_id' => $admission->id, 'registration_id' => $admission->registration_id]);

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->delete('/my/admissions/'.$admission->id.'/holder')
        ->assertRedirect()
        ->assertSessionHasErrors('holder');

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->post('/my/admissions/'.$admission->id.'/holder')
        ->assertSessionHasErrors('holder');
});

it('403s the holder, a stranger, and sends a guest to login', function (): void {
    $buyer = User::factory()->create();
    $holder = User::factory()->create();
    $admission = holderRoutesPlaceholder($buyer, (string) Str::ulid());
    $admission->forceFill(['holder_user_id' => $holder->id, 'state' => AdmissionState::Assigned])->save();

    $this->actingAs($holder)->delete('/my/admissions/'.$admission->id.'/holder')->assertForbidden();
    $this->actingAs(User::factory()->create())->post('/my/admissions/'.$admission->id.'/invite')->assertForbidden();

    // `actingAs()` stays signed in for the rest of the test; a guest has to
    // be made one (as `CheckoutPageTest` does).
    auth()->logout();
    $this->post('/my/admissions/'.$admission->id.'/holder')->assertRedirect('/login');
});

it('invites by address: binds and mails the link; without an address mints a copyable one', function (): void {
    $buyer = User::factory()->create();
    $admission = holderRoutesPlaceholder($buyer, (string) Str::ulid());
    Queue::fake();

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->post('/my/admissions/'.$admission->id.'/invite', ['email' => 'friend@example.test'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $link = AdmissionClaimLink::query()->where('admission_id', $admission->id)->firstOrFail();

    expect($link->intended_email)->toBe('friend@example.test')
        ->and($link->isClaimable())->toBeTrue();

    Queue::assertPushed(SendQueuedMessage::class, fn (SendQueuedMessage $job): bool => $job->message->recipientEmail === 'friend@example.test');

    Queue::fake();

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->post('/my/admissions/'.$admission->id.'/invite')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Re-issuing supersedes: one live link, unbound, and no mail for it.
    $live = AdmissionClaimLink::query()->where('admission_id', $admission->id)->whereNull('revoked_at')->get();

    expect($live)->toHaveCount(1)
        ->and($live->first()->intended_email)->toBeNull()
        ->and($link->fresh()->revoked_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

it('refuses an invalid address and an invite for a ticket that already has a holder', function (): void {
    $buyer = User::factory()->create();
    $admission = holderRoutesPlaceholder($buyer, (string) Str::ulid());

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->post('/my/admissions/'.$admission->id.'/invite', ['email' => 'not-an-address'])
        ->assertSessionHasErrors('email');

    $admission->forceFill(['holder_user_id' => $buyer->id, 'state' => AdmissionState::Assigned])->save();

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->post('/my/admissions/'.$admission->id.'/invite', ['email' => 'friend@example.test'])
        ->assertSessionHasErrors('email');

    expect(AdmissionClaimLink::query()->where('admission_id', $admission->id)->count())->toBe(0);
});

it('withdraws the live invitation', function (): void {
    $buyer = User::factory()->create();
    $admission = holderRoutesPlaceholder($buyer, (string) Str::ulid());
    $link = app(IssueAdmissionClaimLink::class)->handle($admission, null);

    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->delete('/my/admissions/'.$admission->id.'/invite')
        ->assertRedirect();

    expect($link->fresh()->revoked_at)->not->toBeNull();

    // Nothing live: still a quiet success, nothing to withdraw.
    $this->actingAs($buyer)
        ->from('/my/registrations/'.$admission->registration_id)
        ->delete('/my/admissions/'.$admission->id.'/invite')
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});
