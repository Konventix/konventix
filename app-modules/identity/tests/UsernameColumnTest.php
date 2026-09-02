<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\UsernameGenerator;
use Laravel\Fortify\Features;

it('refuses a second username differing only by case', function (): void {
    User::factory()->create(['username' => 'ada']);

    expect(fn () => User::factory()->create(['username' => 'ADA']))
        ->toThrow(QueryException::class);
});

/*
 * ADR-0051 §3, amended 2026-09-02 (#143): registration asks for the handle and
 * stores what the person typed. Nothing is generated here any more — this used
 * to post without a username and assert the generator had filled one in.
 * UsernameGenerator::FORMAT is still the right shape to assert against: it is
 * the same constant ProfileValidationRules::usernameRules() validates with.
 */
it('stores the username chosen at registration', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->withBrand();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'username-column',
        'email' => 'usernamecolumn@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'usernamecolumn@example.com')->firstOrFail();
    expect($user->username)->toBe('username-column')
        ->and($user->username)->toMatch(UsernameGenerator::FORMAT);
});
