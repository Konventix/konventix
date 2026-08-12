<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Laravel\Fortify\Features;

/**
 * Registration on a brand host (module TODO.md, task scope item 1/6): a
 * fresh account, the ADR-0016 brand membership row via the existing
 * `RecordBrandMembership` listener, and the verification mail
 * `Features::emailVerification()` requires. `Notification::fake()` is scoped
 * to `VerifyEmail`, never a blanket `Event::fake()` — .ai/rules/tests.md: a
 * blanket fake swallows the `eloquent.creating` event the `BelongsToBrand`
 * trait hooks into, and `BrandMembership::brand_id` would never get stamped.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('a new user can register on a brand host', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'test@example.com')->firstOrFail();
    expect($user->name)->toBe('Test User');
    expect($user->hasVerifiedEmail())->toBeFalse();

    // ADR-0016: registering establishes the user↔brand membership via the
    // existing listener — this PR must not duplicate that behaviour.
    expect(BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->count())
        ->toBe(1);

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('registration requires a name, email and password', function () {
    $response = $this->post(route('register.store'), []);

    $response->assertSessionHasErrors(['name', 'email', 'password']);
    $this->assertGuest();
});

test('registration requires the password confirmation to match', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'not-the-same-password',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('registration rejects an email already used on another brand', function () {
    // ADR-0016: email is unique operator-wide. Documented deviation
    // (RegisterData's docblock, TODO.md): this surfaces as an ordinary
    // validation error rather than the "indistinguishable from success"
    // response the ADR asks for.
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
