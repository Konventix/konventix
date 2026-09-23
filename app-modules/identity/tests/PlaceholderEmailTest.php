<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Mail\AuthMailer;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\PlaceholderEmail;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\InboxNotification;
use Laravel\Fortify\Features;

/**
 * #293: an account carried over from Eventula with no address of its own
 * (Steam-only) signs in through Steam, is asked for an address before it
 * reaches anything behind `verified`, and never has mail sent to the
 * stand-in. `placeholderEmail*` helper names are scoped to this file
 * (.ai/rules/tests.md).
 */
function placeholderEmailAccount(string $steamId = '76561198000000293'): User
{
    $user = User::factory()->unverified()->create([
        'email' => PlaceholderEmail::forSteam($steamId),
        'password' => null,
    ]);

    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => $steamId,
    ]);

    return $user;
}

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

it('mints and recognises the placeholder', function (): void {
    $email = PlaceholderEmail::forSteam('76561198000000293');

    expect($email)->toBe('steam-76561198000000293@pending.invalid')
        ->and(PlaceholderEmail::is($email))->toBeTrue()
        ->and(PlaceholderEmail::is('Steam-1@PENDING.INVALID'))->toBeTrue()
        ->and(PlaceholderEmail::is('erika@example.test'))->toBeFalse()
        ->and(PlaceholderEmail::is(null))->toBeFalse();
});

it('sends a Steam login for a placeholder account straight to the address form', function (): void {
    $this->withBrand();
    $user = placeholderEmailAccount();

    identityCallbackFrom($this, '76561198000000293')
        ->assertRedirect(route('verification.notice'));

    expect(auth()->id())->toBe($user->getKey());
});

it('shows the address form instead of the verification notice for a placeholder account', function (): void {
    $this->withBrand();

    $this->actingAs(placeholderEmailAccount())
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/SetEmail'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/VerifyEmail'));
});

it('keeps a placeholder account behind the verified gate', function (): void {
    $this->withBrand();

    $this->actingAs(placeholderEmailAccount())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

it('stores the address, projects it, and sends exactly one verification link to it', function (): void {
    $this->withBrand();
    Queue::fake();
    Event::fake([UserProfileUpdated::class]);
    $user = placeholderEmailAccount();

    $this->actingAs($user)
        ->post(route('account-email.store'), ['email' => 'erika@example.test'])
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-sent');

    $user->refresh();
    expect($user->email)->toBe('erika@example.test')
        ->and($user->hasPlaceholderEmail())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->userId === $user->getKey()
        && $event->email === 'erika@example.test');

    $pushed = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'identity.verify-email',
    );
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->message->recipientEmail)->toBe('erika@example.test');
});

it('verifies through the link sent to the new address and passes the gate afterwards', function (): void {
    $this->withBrand();
    $user = placeholderEmailAccount();

    $this->actingAs($user)->post(route('account-email.store'), ['email' => 'erika@example.test']);
    $user->refresh();

    $this->actingAs($user)->get(URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1('erika@example.test')],
    ));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect('/');
});

it('rejects an address another account already uses and leaves the placeholder in place', function (): void {
    $this->withBrand();
    User::factory()->create(['email' => 'taken@example.test']);
    $user = placeholderEmailAccount();

    $this->actingAs($user)
        ->from(route('verification.notice'))
        ->post(route('account-email.store'), ['email' => 'taken@example.test'])
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHasErrors('email');

    expect($user->fresh()->hasPlaceholderEmail())->toBeTrue();
});

it('refuses the address form to an account that already has a real address', function (): void {
    $this->withBrand();
    $user = User::factory()->unverified()->create(['email' => 'real@example.test']);

    $this->actingAs($user)
        ->post(route('account-email.store'), ['email' => 'other@example.test'])
        ->assertForbidden();

    expect($user->fresh()->email)->toBe('real@example.test');
});

it('sends no password-reset mail to a placeholder address', function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
    $this->withBrand();
    Queue::fake();
    Event::fake([MessageSuppressed::class]);
    placeholderEmailAccount();

    $this->post(route('password.email'), ['email' => PlaceholderEmail::forSteam('76561198000000293')]);

    Queue::assertNotPushed(SendQueuedMessage::class);
    Event::assertDispatched(MessageSuppressed::class, fn (MessageSuppressed $event): bool => $event->templateKey === 'identity.password-reset'
        && $event->reasonCode === 'undeliverable_address');
});

it('sends no verification mail to a placeholder address even when asked to resend', function (): void {
    $this->withBrand();
    Queue::fake();
    $user = placeholderEmailAccount();

    $this->actingAs($user)->post(route('verification.send'));

    Queue::assertNotPushed(SendQueuedMessage::class);
});

it('puts one in-app notice in the inbox of a placeholder account, and none for a real address', function (): void {
    $brand = $this->withBrand();
    $user = placeholderEmailAccount();

    // What the importer does right before calling the mailer: the contract
    // event projects Messaging's recipient row for the account.
    event(new UserRegistered(
        userId: $user->getKey(),
        brandId: $brand->getKey(),
        email: $user->email,
        displayName: $user->name,
        username: $user->username,
        locale: $user->locale ?? 'en',
        registeredAt: now()->toIso8601String(),
    ));

    app(AuthMailer::class)->sendEmailMissingNotice($user, $brand->getKey());

    $notifications = InboxNotification::withoutBrandScope()->where('recipient_id', $user->getKey())->get();
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->topic_key)->toBe('identity.email-missing')
        ->and($notifications->first()->url)->toContain('/email/verify');

    $real = User::factory()->create();
    app(AuthMailer::class)->sendEmailMissingNotice($real, $brand->getKey());

    expect(InboxNotification::withoutBrandScope()->where('recipient_id', $real->getKey())->count())->toBe(0);
});
