<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;

/**
 * `/settings` is a page of its own rather than a redirect into the profile
 * form: the topbar's Account menu points at it as a destination, and
 * dropping someone straight into one form hides that anything else lives
 * there.
 */
it('renders the account hub for a signed-in user', function (): void {
    $this->withBrand();

    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/Index'));
});

it('keeps the hub behind auth', function (): void {
    $this->withBrand();

    $this->get('/settings')->assertRedirect('/login');
});
