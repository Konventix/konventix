<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Middleware\ResolveBrand;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0025: current brand ⇒ current brand's Reverb app. A broadcast fired
 * while brand A is resolved must be signed with brand A's credentials — the
 * one mistake a per-brand process could not express, pinned here instead.
 */
beforeEach(function (): void {
    Route::middleware(ResolveBrand::class)
        ->get('/broadcast-probe', fn (): array => [
            'key' => config('broadcasting.connections.reverb.key'),
            'app_id' => config('broadcasting.connections.reverb.app_id'),
        ]);
});

it('signs broadcasts with the resolved brand\'s reverb credentials', function (): void {
    $brand = Brand::factory()->withDomain('sxlan.test')->create();

    $this->get('http://sxlan.test/broadcast-probe')
        ->assertOk()
        ->assertJson([
            'key' => $brand->reverb_app_key,
            'app_id' => $brand->getKey(),
        ]);
});

it('switches credentials when a different brand is resolved', function (): void {
    $first = Brand::factory()->withDomain('first.test')->create();
    $second = Brand::factory()->withDomain('second.test')->create();

    $this->get('http://first.test/broadcast-probe')
        ->assertJson(['key' => $first->reverb_app_key]);

    $this->get('http://second.test/broadcast-probe')
        ->assertJson(['key' => $second->reverb_app_key, 'app_id' => $second->getKey()]);
});

it('shares the brand\'s theme tokens with the root template', function (): void {
    Route::middleware(ResolveBrand::class)
        ->get('/token-probe', fn (): array => view()->shared('brandTokens'));

    Brand::factory()
        ->withDomain('themed.test')
        ->create(['theme_tokens' => ['accent' => 'hsl(210 90% 45%)']]);

    $this->get('http://themed.test/token-probe')
        ->assertOk()
        ->assertJson(['accent' => 'hsl(210 90% 45%)']);
});
