<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\CreateBrand;
use Konventix\Platform\Data\BrandData;
use Konventix\Platform\Events\BrandCreated;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * #287: nothing minted a brand's entrance signing key, so the first ticket
 * download of every brand answered 500 — and the exception told the
 * operator to run a command that did not exist.
 *
 * Three halves of one fix are pinned here: the `BrandCreated` listener, the
 * `admission:rotate-signing-key` command in both its modes, and the
 * regression itself. The deploy-path backfill's own wiring
 * (`konventix:migrate`) is pinned in `tests/Feature/MigrateOnStartCommandTest.php`,
 * where that command's other steps already are.
 *
 * The closures below stay file-local on purpose: helper *function* names are
 * process-global across every module's test files (`.ai/rules/tests.md`), and
 * nothing outside this file needs them.
 */
$signingKeys = static fn (Brand $brand) => EntranceSigningKey::withoutBrandScope()
    ->where('brand_id', $brand->getKey())
    ->whereNull('retired_at')
    ->get();

it('answers 200 on the first ticket download of a brand created a moment ago', function () use ($signingKeys): void {
    // The regression the issue asks for: before this, the buyer's very first
    // `/my/registrations/{id}/ticket` on a new brand was a 500 naming
    // `admission:rotate-signing-key`.
    $operator = Operator::factory()->create();

    $brand = app(CreateBrand::class)->handle($operator, new BrandData(
        name: 'Fresh Tickets',
        slug: 'esk-fresh',
        hosts: ['esk-fresh.test'],
    ));

    expect($signingKeys($brand))->toHaveCount(1);

    $this->withBrand($brand);

    $buyer = User::factory()->create();
    $registrationId = (string) Str::ulid();

    Admission::factory()->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
    ]);

    $response = $this->actingAs($buyer)
        ->get('http://esk-fresh.test/my/registrations/'.$registrationId.'/ticket')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

it('mints a key when a brand announces its creation', function () use ($signingKeys): void {
    $brand = $this->withBrand();

    expect($signingKeys($brand))->toHaveCount(0);

    event(new BrandCreated(
        brandId: $brand->getKey(),
        operatorId: (string) $brand->operator_id,
        slug: $brand->slug,
        name: $brand->name,
    ));

    expect($signingKeys($brand))->toHaveCount(1);
});

it('leaves a brand that already has an active key alone when the event is replayed', function () use ($signingKeys): void {
    // Idempotency is the whole point: a replay must not retire the key
    // every QR already in circulation was signed with.
    $brand = $this->withBrand();
    $existing = app(RotateEntranceSigningKey::class)->handle($brand->getKey());

    foreach (range(1, 2) as $ignored) {
        event(new BrandCreated(
            brandId: $brand->getKey(),
            operatorId: (string) $brand->operator_id,
            slug: $brand->slug,
            name: $brand->name,
        ));
    }

    expect($signingKeys($brand)->pluck('id')->all())->toBe([$existing->getKey()])
        ->and($existing->fresh()->retired_at)->toBeNull();
});

it('mints a key for one named brand, by slug and by ulid', function () use ($signingKeys): void {
    $bySlug = Brand::factory()->create(['slug' => 'esk-by-slug']);
    $byUlid = Brand::factory()->create(['slug' => 'esk-by-ulid']);

    $this->artisan('admission:rotate-signing-key', ['brand' => 'esk-by-slug'])->assertSuccessful();
    $this->artisan('admission:rotate-signing-key', ['brand' => $byUlid->getKey()])->assertSuccessful();

    expect($signingKeys($bySlug))->toHaveCount(1)
        ->and($signingKeys($byUlid))->toHaveCount(1);
});

it('rotates a named brand that already signs, retiring the previous key without expiring it', function () use ($signingKeys): void {
    $brand = Brand::factory()->create(['slug' => 'esk-rotate']);
    $previous = app(RotateEntranceSigningKey::class)->handle($brand->getKey());

    $this->artisan('admission:rotate-signing-key', ['brand' => 'esk-rotate'])->assertSuccessful();

    $current = $signingKeys($brand);

    expect($current)->toHaveCount(1)
        ->and($current->first()->getKey())->not->toBe($previous->getKey())
        // Retiring is not deleting (ADR-0033): the old key keeps verifying.
        ->and($previous->fresh()->retired_at)->not->toBeNull()
        ->and($previous->fresh()->expires_at->isFuture())->toBeTrue();
});

it('refuses a brand nobody has', function (): void {
    $this->artisan('admission:rotate-signing-key', ['brand' => 'no-such-brand'])->assertFailed();
});

it('refuses to guess when given neither a brand nor --all', function (): void {
    $this->artisan('admission:rotate-signing-key')->assertFailed();
    $this->artisan('admission:rotate-signing-key', ['brand' => 'esk-anything', '--all' => true])->assertFailed();
});

it('backfills only the brands without an active key and is a no-op on the rest', function () use ($signingKeys): void {
    $with = Brand::factory()->create(['slug' => 'esk-has-one']);
    $without = Brand::factory()->create(['slug' => 'esk-has-none']);

    $kept = app(RotateEntranceSigningKey::class)->handle($with->getKey());

    $this->artisan('admission:rotate-signing-key', ['--all' => true])->assertSuccessful();

    expect($signingKeys($without))->toHaveCount(1)
        // Untouched, same row, still the one that signs.
        ->and($signingKeys($with)->pluck('id')->all())->toBe([$kept->getKey()]);

    // And running it again changes nothing at all.
    $before = EntranceSigningKey::withoutBrandScope()->count();

    $this->artisan('admission:rotate-signing-key', ['--all' => true])->assertSuccessful();

    expect(EntranceSigningKey::withoutBrandScope()->count())->toBe($before);
});

it('ignores a retired or expired key when deciding a brand has none', function () use ($signingKeys): void {
    $brand = $this->withBrand();

    EntranceSigningKey::factory()->retired()->create();
    EntranceSigningKey::factory()->expired()->create();

    $this->artisan('admission:rotate-signing-key', ['--all' => true])->assertSuccessful();

    expect($signingKeys($brand))->toHaveCount(1);
});
