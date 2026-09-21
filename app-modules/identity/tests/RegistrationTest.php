<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Laravel\Fortify\Features;

/**
 * Registration on a brand host (module TODO.md, task scope item 1/6): a
 * fresh account, the ADR-0016 brand membership row via the existing
 * `RecordBrandMembership` listener, and the verification mail
 * `Features::emailVerification()` requires — since ADR-0048 point 5, sent
 * through Messaging (`AuthMailTest.php` covers that path in full; the
 * `Queue::fake()` here only asserts the push happened, never a blanket
 * `Event::fake()` — .ai/rules/tests.md: a blanket fake swallows the
 * `eloquent.creating` event the `BelongsToBrand` trait hooks into, and
 * `BrandMembership::brand_id` would never get stamped).
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('a new user can register on a brand host', function () {
    Queue::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'test-user',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'test@example.com')->firstOrFail();
    expect($user->name)->toBe('Test User');
    expect($user->username)->toBe('test-user');
    expect($user->hasVerifiedEmail())->toBeFalse();

    // ADR-0016: registering establishes the user↔brand membership via the
    // existing listener — this PR must not duplicate that behaviour.
    expect(BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->count())
        ->toBe(1);

    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'identity.verify-email'
            && $job->message->recipientId === $user->getKey(),
    );
});

test('registration requires a name, username, email and password', function () {
    $response = $this->post(route('register.store'), []);

    $response->assertSessionHasErrors(['name', 'username', 'email', 'password']);
    $this->assertGuest();
});

test('registration requires the password confirmation to match', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'test-user',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'not-the-same-password',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('registration with an email already used on another brand answers exactly like a fresh signup', function () {
    // ADR-0016/#18: no validation error, no new account, nobody logged in —
    // and the same redirect a genuine registration gets. EmailEnumerationTest
    // covers the byte-for-byte comparison against a real signup and the
    // notice mail; this test is the registration-specific shape: no account
    // was created, and the existing one was not touched beyond the mail.
    $existing = User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'test-user',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertGuest();

    expect(User::where('email', 'taken@example.com')->count())->toBe(1)
        ->and(User::where('username', 'test-user')->exists())->toBeFalse()
        ->and($existing->fresh()->name)->toBe($existing->name);
});

test('registration explains the username shape and refuses reserved or taken handles', function () {
    User::factory()->create(['username' => 'ada-lovelace']);

    $attempt = fn (string $username) => $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => $username,
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $attempt('Ada Lovelace')->assertSessionHasErrors(['username' => 'Use 3-32 lowercase letters, digits and hyphens, starting and ending with a letter or digit.']);
    $attempt('admin')->assertSessionHasErrors(['username' => 'This username is reserved.']);
    $attempt('ada-lovelace')->assertSessionHasErrors(['username' => 'This username is already taken.']);
    $this->assertGuest();
});
