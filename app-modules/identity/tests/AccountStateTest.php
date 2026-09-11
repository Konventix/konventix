<?php

declare(strict_types=1);

use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Models\User;

/**
 * The locked-state login gate (task scope item 6): `Fortify::authenticateUsing`
 * (wired in `IdentityServiceProvider`) delegates to `Actions\AuthenticateUser`,
 * which is the single place both the plain and two-factor-redirect login
 * paths validate credentials — see that class's docblock for why.
 */
test('a locked account cannot log in even with the correct password', function () {
    $user = User::factory()->locked()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('an active account can log in', function () {
    $user = User::factory()->create();
    expect($user->state)->toBe(AccountState::Active);

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a locked account with the wrong password still gets the generic failure', function () {
    $user = User::factory()->locked()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

/**
 * #81: `MergeUserAccounts` clears the closed account's password, so this is
 * really exercising the same "no password at all" branch a Steam-only
 * account already hits — see `AuthenticateUser`'s belt-and-braces
 * `AccountState::Merged` check for why the explicit state check still
 * exists even though this path never reaches it.
 */
test('a merged account cannot log in, even naming its own former password', function () {
    $user = User::factory()->merged()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
