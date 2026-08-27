<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Registration\Models\RegistrationType;

/**
 * ADR-0047 point 5: Registration applies the `ensure-waiting-room` alias to
 * its own public form, beside `require-consent`
 * (`routes/web/registration.php`) — this module imports nothing from
 * Commerce, it only names the alias string, the same way it names
 * `require-consent`.
 */
it('redirects the registration form to the waiting room while the feature is on', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:waiting-room', $brand);

    $type = RegistrationType::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertRedirect('/waiting-room');
});

it('renders the registration form as usual while the waiting room feature is off', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create(['name' => 'Community Day']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertOk();
});
