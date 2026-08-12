<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Middleware\ResolveBrand;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0001: sessions are brand-scoped. The cookie name carries the brand slug,
 * so widening SESSION_DOMAIN can never make one brand's session readable on
 * another brand's host.
 */
beforeEach(function (): void {
    Route::middleware(ResolveBrand::class)
        ->get('/session-probe', fn (): string => (string) config('session.cookie'));
});

it('names the session cookie after the resolved brand', function (): void {
    Brand::factory()->withDomain('sxlan.test')->create(['slug' => 'sxlan']);

    $this->get('http://sxlan.test/session-probe')
        ->assertOk()
        ->assertSee('sxlan', escape: false);
});

it('gives two brands two different session cookies', function (): void {
    Brand::factory()->withDomain('first.test')->create(['slug' => 'first']);
    Brand::factory()->withDomain('second.test')->create(['slug' => 'second']);

    $first = $this->get('http://first.test/session-probe')->getContent();
    $second = $this->get('http://second.test/session-probe')->getContent();

    expect($first)->not->toBe($second);
});
