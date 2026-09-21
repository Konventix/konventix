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
    '/settings/linked-accounts',
    '/settings/appearance',
    '/settings/notifications',
    '/settings/privacy',
]);

it('renders the settings sub-navigation beside each section', function (string $path): void {
    $this->withBrand();

    expect(settingsPageHtml($this, $path))->toContain('aria-label="Settings"');
})->with([
    '/settings/profile',
    '/settings/security',
    '/settings/linked-accounts',
    '/settings/appearance',
    '/settings/notifications',
    '/settings/privacy',
]);

it('leaves the hub itself without the sub-navigation', function (): void {
    $this->withBrand();

    // The hub already lists all six sections as cards; repeating them in a
    // sidebar beside the same cards would be saying it twice.
    expect(settingsPageHtml($this, '/settings'))->not->toContain('aria-label="Settings"');
});

/**
 * Every settings page declares `breadcrumbs` as a layout prop, and the
 * layout has to declare a prop of that name to receive them — without one
 * Inertia dropped the declaration and no page showed a trail. The trail
 * starts at the hub, which is the one link every section shares.
 */
it('renders a breadcrumb trail back to the hub on each section', function (string $path): void {
    $this->withBrand();

    expect(settingsPageHtml($this, $path))
        ->toContain('aria-label="breadcrumb"')
        ->toContain('href="/settings"');
})->with([
    '/settings/profile',
    '/settings/security',
    '/settings/linked-accounts',
    '/settings/appearance',
    '/settings/notifications',
    '/settings/privacy',
]);

it('leaves the hub itself without a breadcrumb trail', function (): void {
    $this->withBrand();

    // A trail of one entry pointing at the page it is on says nothing.
    expect(settingsPageHtml($this, '/settings'))->not->toContain('aria-label="breadcrumb"');
});

it('renders auth pages inside the auth shell', function (): void {
    $this->withBrand();

    // Same failure mode, same fix: auth pages declare layout props too.
    expect($this->get('/login')->assertOk()->getContent())
        ->toContain('Log in to your account');
});
