<?php

declare(strict_types=1);

use Konventix\Platform\Models\Brand;
use Konventix\Platform\Pages\RegistrantAudience;
use Konventix\Registration\Audience\RegistrationRegistrantAudience;
use Konventix\Registration\Models\Registration;

/**
 * Registration binds Platform's `RegistrantAudience` contract (Task 20) —
 * the inversion `WaitingRoomGate` set: Platform owns the interface and
 * asks, this module answers from its own table. A registrant of a brand is
 * whoever holds a confirmed registration for one of its events; nothing
 * less counts, and another brand's registration never does.
 */
it('binds the RegistrantAudience contract to its own implementation', function (): void {
    expect(app(RegistrantAudience::class))->toBeInstanceOf(RegistrationRegistrantAudience::class);
});

it('names a confirmed registrant of the brand and nobody else', function (): void {
    $brand = $this->withBrand();

    $confirmed = Registration::factory()->confirmed()->create();
    $submitted = Registration::factory()->create();
    $cancelled = Registration::factory()->cancelled()->create();

    $audience = app(RegistrantAudience::class);

    expect($audience->isRegistrantOf($brand->getKey(), $confirmed->registrant_user_id))->toBeTrue()
        ->and($audience->isRegistrantOf($brand->getKey(), $submitted->registrant_user_id))->toBeFalse()
        ->and($audience->isRegistrantOf($brand->getKey(), $cancelled->registrant_user_id))->toBeFalse()
        // A confirmed registration on this brand says nothing about another.
        ->and($audience->isRegistrantOf(Brand::factory()->create(['slug' => 'aud-other'])->getKey(), $confirmed->registrant_user_id))->toBeFalse();
});
