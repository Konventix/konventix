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
