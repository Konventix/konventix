<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users are sent to the brand landing page', function () {
    // Brand hosts have no dashboard: the route survives only as the
    // post-login redirect target and forwards to the landing page.
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect('/');
});
