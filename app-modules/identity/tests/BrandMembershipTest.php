<?php

declare(strict_types=1);

use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0016: one account across the operator's brands; the per-brand
 * relationship is a membership row, written on first contact and never
 * duplicated.
 */
it('records a membership on first login under a brand', function (): void {
    Brand::factory()->withDomain('sxlan.test')->create();
    $user = User::factory()->create(['password' => 'secret-password-1']);

    $this->post('http://sxlan.test/login', [
        'email' => $user->email,
        'password' => 'secret-password-1',
    ]);

    $this->assertAuthenticated();

    expect(BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->count())
        ->toBe(1);
});

it('does not duplicate the membership on a second login', function (): void {
    Brand::factory()->withDomain('sxlan.test')->create();
    $user = User::factory()->create(['password' => 'secret-password-1']);

    foreach ([1, 2] as $attempt) {
        $this->post('http://sxlan.test/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ]);
        $this->post('http://sxlan.test/logout');
    }

    expect(BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->count())
        ->toBe(1);
});

it('records one membership per brand for the same account', function (): void {
    Brand::factory()->withDomain('first.test')->create();
    Brand::factory()->withDomain('second.test')->create();
    $user = User::factory()->create(['password' => 'secret-password-1']);

    foreach (['first.test', 'second.test'] as $host) {
        $this->post("http://{$host}/login", [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ]);
        $this->post("http://{$host}/logout");
    }

    $memberships = BrandMembership::withoutBrandScope()
        ->where('user_id', $user->getKey())
        ->get();

    expect($memberships)->toHaveCount(2)
        ->and($memberships->pluck('brand_id')->unique())->toHaveCount(2);
});

it('keeps first_seen_at from the first contact', function (): void {
    $this->withBrand();

    $membership = BrandMembership::factory()->create(['first_seen_at' => now()->subDays(30)]);

    // A later firstOrCreate (what the listener does) must not touch it.
    BrandMembership::query()->firstOrCreate(
        ['user_id' => $membership->user_id, 'brand_id' => $membership->brand_id],
        ['first_seen_at' => now()],
    );

    expect($membership->fresh()->first_seen_at->isSameDay(now()->subDays(30)))->toBeTrue();
});
