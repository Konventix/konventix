<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * ADR-0068 §4 on the wire: the page's locale is the resolved one, the
 * `<html lang>` follows it (ADR-0055), and the admin host resolves
 * operator → user with no brand in between.
 */
it('renders a brand host in the brand locale for a guest', function (): void {
    $operator = Operator::factory()->create(['locale' => 'en']);
    $brand = Brand::factory()->withDomain('de.brand.test')->create(['operator_id' => $operator->id, 'locale' => 'de']);

    $this->get('http://de.brand.test/')
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'de')
            ->where('localeChain', ['de', 'en'])
            ->where('contentLocale', 'de')
            ->where('locales.0.code', 'en'));
});

it('lets a signed-in person override the brand', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $brand = Brand::factory()->withDomain('over.brand.test')->create(['operator_id' => $operator->id, 'locale' => null]);
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->get('http://over.brand.test/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'en')
            ->where('contentLocale', 'de'));
});

it('resolves operator → user on the admin host', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $operator->forceFill(['admin_host' => 'admin.locale.test'])->save();
    $user = User::factory()->create(['locale' => null]);

    // Not /login: Fortify's guest middleware redirects a signed-in person
    // away from it, and a redirect renders no <html lang>.
    $this->actingAs($user)
        ->get('http://admin.locale.test/settings/appearance')
        ->assertSee('<html lang="de"', false);
});
