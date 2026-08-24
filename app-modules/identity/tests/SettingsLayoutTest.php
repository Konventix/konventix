<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;

/**
 * Every settings page renders inside the brand shell and the settings
 * sub-navigation.
 *
 * This is asserted against the rendered HTML rather than the Inertia props
 * because the failure it guards is invisible from the props: a page that
 * declares layout *props* (`defineOptions({ layout: { breadcrumbs } })`,
 * Inertia v3) is not declaring a layout, and the resolver has to hand
 * Inertia a default anyway or the page renders bare — right status, right
 * component, no shell.
 */
function settingsPageHtml(object $test, string $path): string
{
    return $test->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->getContent();
}

it('renders every settings page inside the brand shell', function (string $path): void {
    $this->withBrand();

    expect(settingsPageHtml($this, $path))
        ->toContain('aria-label="Main"')
        ->toContain('github.com/konventix/konventix');
})->with([
    '/settings',
    '/settings/profile',
    '/settings/security',
    '/settings/appearance',
    '/settings/notifications',
]);

it('renders the settings sub-navigation beside each section', function (string $path): void {
    $this->withBrand();

    expect(settingsPageHtml($this, $path))->toContain('aria-label="Settings"');
})->with([
    '/settings/profile',
    '/settings/security',
    '/settings/appearance',
    '/settings/notifications',
]);

it('leaves the hub itself without the sub-navigation', function (): void {
    $this->withBrand();

    // The hub already lists all six sections as cards; repeating them in a
    // sidebar beside the same cards would be saying it twice.
    expect(settingsPageHtml($this, '/settings'))->not->toContain('aria-label="Settings"');
});

it('renders auth pages inside the auth shell', function (): void {
    $this->withBrand();

    // Same failure mode, same fix: auth pages declare layout props too.
    expect($this->get('/login')->assertOk()->getContent())
        ->toContain('Log in to your account');
});
