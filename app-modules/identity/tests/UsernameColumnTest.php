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

it('registers a user whose username matches the generator format', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->withBrand();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'usernamecolumn@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'usernamecolumn@example.com')->firstOrFail();
    expect($user->username)->toMatch(UsernameGenerator::FORMAT);
});
