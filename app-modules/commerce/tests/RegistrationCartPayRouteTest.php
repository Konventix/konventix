<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Identity\Models\User;

/**
 * A4 (#210): `/pay/registration/{ulid}` is the pay page for a registration
 * whose settlement became an order (ADR-0039). With `commerce:shop` on, an
 * `instant` settlement becomes a cart line instead (ADR-0047 point 3), and
 * this route answered a blank 404 to the one link the registrant was given.
 * It now hands the payer on to the place the money is actually paid.
 */
function payRouteRegistrantWithCartLine(): array
{
    $payer = User::factory()->create();
    $registrationId = (string) Str::ulid();

    $cart = Cart::factory()->forPayer($payer->getKey())->create();
    CartLine::factory()->forCart($cart)->registration()->create(['ref_id' => $registrationId]);

    return [$registrationId, $payer, $cart];
}

it('redirects the pay page to the cart when the registration settled into a cart line', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    [$registrationId, $payer] = payRouteRegistrantWithCartLine();

    $this->actingAs($payer)
        ->get('/pay/registration/'.$registrationId)
        ->assertRedirect('/cart');
});

it('redirects a checkout start to the cart when the registration settled into a cart line', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    [$registrationId, $payer] = payRouteRegistrantWithCartLine();

    $this->actingAs($payer)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => (string) Str::ulid()])
        ->assertRedirect('/cart');
});

it('still 404s a registration with neither an order nor a live cart line', function (): void {
    $this->withBrand();

    $this->actingAs(User::factory()->create())
        ->get('/pay/registration/'.Str::ulid())
        ->assertNotFound();
});

it('404s once the cart line\'s reservation has lapsed rather than sending the payer to an empty cart', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $payer = User::factory()->create();
    $registrationId = (string) Str::ulid();
    $cart = Cart::factory()->forPayer($payer->getKey())->expired()->create();
    CartLine::factory()->forCart($cart)->registration()->create(['ref_id' => $registrationId]);

    $this->actingAs($payer)
        ->get('/pay/registration/'.$registrationId)
        ->assertNotFound();
});
