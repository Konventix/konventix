<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\PlaceOrderFromCart;
use Konventix\Commerce\Actions\PlaceRegistrationLineInCart;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Exceptions\SaleWindowClosedException;
use Konventix\Commerce\Models\CartLine;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * #218 (#216 draft i): a registration-kind cart line carries its own sales
 * window, and `PlaceRegistrationLineInCart` — the one place Commerce enforces
 * it — refuses a closed one.
 *
 * Commerce never reads Registration's table (ADR-0004): the window arrives as
 * two nullable ISO-8601 scalars on `RegistrationSettlementRequested`, where
 * null means "not window-bound", and is snapshotted onto the cart line
 * exactly as `label`, `unit_amount_net` and `tax_class` already are. That
 * snapshot is for display and audit: the checkout deliberately does not
 * re-check it, because a line placed while the sale was open stays payable
 * within the cart's own hold (`PlaceOrderFromCart`).
 *
 * Helper names prefixed `saleWindow*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function saleWindowBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

/**
 * @return list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>
 */
function saleWindowLines(): array
{
    return [['label' => 'Weekend Ticket', 'quantity' => 1, 'unit_amount_net' => '50.00', 'tax_class' => 'standard']];
}

it('places a registration line and snapshots the sales window onto it', function (): void {
    $brand = saleWindowBrand();
    $registrantUserId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: $registrantUserId,
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleStartsAt: '2026-09-01T18:00:00+00:00',
        saleEndsAt: '2026-10-01T18:00:00+00:00',
    );

    expect($line->kind)->toBe(CartLineKind::Registration)
        ->and($line->sale_starts_at?->toIso8601String())->toBe('2026-09-01T18:00:00+00:00')
        ->and($line->sale_ends_at?->toIso8601String())->toBe('2026-10-01T18:00:00+00:00');
});

it('refuses a registration line whose sale has not started', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    expect(fn () => app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleStartsAt: '2026-10-01T18:00:00+00:00',
    ))->toThrow(SaleWindowClosedException::class);

    expect(CartLine::withoutBrandScope()->where('ref_id', $registrationId)->exists())->toBeFalse();
});

it('refuses a registration line whose sale has ended', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    expect(fn () => app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleEndsAt: '2026-09-01T18:00:00+00:00',
    ))->toThrow(SaleWindowClosedException::class);

    expect(CartLine::withoutBrandScope()->where('ref_id', $registrationId)->exists())->toBeFalse();
});

it('places a registration line that declares no window at all', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
    );

    expect($line->sale_starts_at)->toBeNull()
        ->and($line->sale_ends_at)->toBeNull();
});

/**
 * Fix round 1, review finding 2: the checkout deliberately does NOT re-check
 * the window. A line is only ever placed while its sale is open, and the
 * registration it settles already exists and already holds its quota —
 * refusing to let its payer pay would leave them owing something they cannot
 * settle. The snapshot columns stay for display and audit.
 */
it('checks out a registration line whose sale has ended since it was placed', function (): void {
    $brand = saleWindowBrand();
    $user = User::factory()->create();
    $account = cartCheckoutAccount();

    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    CartLine::factory()->forCart($cart)->registration()->create([
        'label' => 'Weekend Ticket',
        'unit_amount_net' => '50.00',
        'sale_ends_at' => '2026-09-08 18:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-09-08 19:00:00Z'));

    $redirect = app(PlaceOrderFromCart::class)->handle(
        $cart->fresh(),
        $account,
        cartCheckoutReturnUrl(),
        cartCheckoutBillingParty($user),
    );

    expect($redirect->url)->not->toBeEmpty()
        ->and($cart->fresh()->checked_out_order_id)->not->toBeNull();
});

/**
 * Fix round 1, review finding 3: the guard runs after the idempotency lookup,
 * so a replayed settlement request still finds the line it already created.
 * Refusing a replay would contradict the class's own idempotency promise and
 * would strand a line that was placed perfectly legitimately.
 */
it('returns the existing line on a replay that arrives after the sale ended', function (): void {
    $brand = saleWindowBrand();
    $registrantUserId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $first = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: $registrantUserId,
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleEndsAt: '2026-09-08 18:00:00',
    );

    $this->travelTo(Carbon::parse('2026-09-09 09:00:00Z'));

    $replay = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: $registrantUserId,
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleEndsAt: '2026-09-08 18:00:00',
    );

    expect($replay->id)->toBe($first->id)
        ->and(CartLine::withoutBrandScope()->where('ref_id', $registrationId)->count())->toBe(1);
});

/**
 * Fix round 1, review finding 5: a non-UTC offset in the payload is stored as
 * the instant it names, not as its wall-clock digits. Eloquent's `datetime`
 * cast does not normalize an offset-bearing string, so the Action parses it
 * and hands the model a UTC Carbon — the same value the guard compares
 * against, so the two can never disagree.
 */
it('normalizes a non-UTC offset to a UTC instant on the line', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleStartsAt: '2026-09-01T18:00:00+02:00',
        saleEndsAt: '2026-10-01T18:00:00+02:00',
    );

    expect($line->fresh()->sale_starts_at?->toIso8601String())->toBe('2026-09-01T16:00:00+00:00')
        ->and($line->fresh()->sale_ends_at?->toIso8601String())->toBe('2026-10-01T16:00:00+00:00');
});

it('reads a non-UTC offset as the instant it names when guarding', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    // 17:00 UTC is 19:00 in +02:00 — past an 18:00+02:00 (= 16:00 UTC) end.
    $this->travelTo(Carbon::parse('2026-10-01 17:00:00Z'));

    expect(fn () => app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleEndsAt: '2026-10-01T18:00:00+02:00',
    ))->toThrow(SaleWindowClosedException::class);
});

/**
 * Fix round 1, review finding 4: Commerce owns exactly one window predicate,
 * `CartLine::isOnSale()`, and `PlaceRegistrationLineInCart` asks an unsaved
 * line rather than re-implementing the comparison. This pins the boundary
 * semantics it shares with `RegistrationType::isOnSale()` on the other side
 * of the module boundary: start inclusive, end exclusive, null = open.
 */
it('reads the window boundaries as start-inclusive and end-exclusive', function (): void {
    $line = new CartLine(['sale_starts_at' => '2026-10-01 18:00:00', 'sale_ends_at' => '2026-10-08 18:00:00']);

    expect($line->isOnSale(Carbon::parse('2026-10-01 17:59:59Z')))->toBeFalse()
        ->and($line->isOnSale(Carbon::parse('2026-10-01 18:00:00Z')))->toBeTrue()
        ->and($line->isOnSale(Carbon::parse('2026-10-08 17:59:59Z')))->toBeTrue()
        ->and($line->isOnSale(Carbon::parse('2026-10-08 18:00:00Z')))->toBeFalse()
        ->and((new CartLine)->isOnSale(Carbon::parse('2026-10-08 18:00:00Z')))->toBeTrue();
});
