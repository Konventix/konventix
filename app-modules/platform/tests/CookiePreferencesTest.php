<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Cookies\CookieRegistry;

/**
 * The cookie-settings dialog's backend (ROADMAP slice 21b). Legal footing:
 * § 25 Abs. 2 Nr. 2 TDDDG and WP194 §3.6 — session/XSRF are necessary;
 * appearance and sidebar state are user-initiated UI customisation, exempt
 * for a session and consented for longer through the note at the control;
 * this preference lets a person withdraw that as easily as they gave it.
 */
it('declares every cookie the platform sets, grouped by category', function (): void {
    $grouped = app(CookieRegistry::class)->grouped();

    expect(collect($grouped['necessary'])->pluck('name')->all())->toContain('XSRF-TOKEN', 'cookie_preferences')
        ->and(collect($grouped['functional'])->pluck('name')->all())->toBe(['appearance', 'sidebar_state'])
        ->and(collect($grouped['necessary'])->pluck('name')->all())->toContain('remember_web_*');
});

it('stores the choice and expires the functional cookies when they are disabled', function (): void {
    $this->withBrand();

    $this->withUnencryptedCookies(['appearance' => 'dark', 'sidebar_state' => 'true'])
        ->post('/cookie-preferences', ['functional' => false])
        ->assertRedirect()
        ->assertCookie('cookie_preferences', 'functional=0', encrypted: false)
        ->assertCookieExpired('appearance')
        ->assertCookieExpired('sidebar_state');
});

it('shares the choice with every page and leaves it null until one is made', function (): void {
    $this->withBrand();

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('cookies.functional', null)
        ->has('cookies.declared.necessary')
        ->has('cookies.declared.functional'));

    $this->withUnencryptedCookie('cookie_preferences', 'functional=1')
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cookies.functional', true));
});

it('ignores a stored appearance once functional cookies are disabled', function (): void {
    $this->withBrand();

    $this->withUnencryptedCookies(['cookie_preferences' => 'functional=0', 'appearance' => 'dark'])
        ->get('/')
        ->assertOk()
        ->assertDontSee('class="dark"', escape: false);
});
