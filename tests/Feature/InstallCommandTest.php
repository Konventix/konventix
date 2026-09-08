<?php

declare(strict_types=1);

use Konventix\Compliance\Actions\DraftPolicyVersion;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    unset($_ENV['KONVENTIX_ADMIN_PASSWORD']);
});

function installArguments(): array
{
    return [
        '--operator-name' => 'Acme Events GmbH',
        '--admin-host' => 'admin.acme.test',
        '--admin-email' => 'root@acme.test',
        '--brand-name' => 'Acme Con',
        '--brand-domain' => 'con.acme.test',
    ];
}

test('install creates operator, admin, role and brand, and is idempotent', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';

    $this->artisan('konventix:install', installArguments())->assertSuccessful();
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    $operator = Operator::query()->firstWhere('name', 'Acme Events GmbH');
    expect(Operator::query()->where('name', 'Acme Events GmbH')->count())->toBe(1)
        ->and($operator->admin_host)->toBe('admin.acme.test');

    $admin = User::query()->firstWhere('email', 'root@acme.test');
    expect($admin)->not->toBeNull()
        ->and($admin->email_verified_at)->not->toBeNull();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    expect($admin->hasRole('Operator Admin'))->toBeTrue();
    $registrar->setPermissionsTeamId(null);

    // Brand is not itself brand-scoped (see its docblock), so a plain query
    // finds it — there is no withoutBrandScope() escape hatch to reach for.
    $brand = Brand::query()->firstWhere('slug', 'acme-con');
    expect($brand)->not->toBeNull()
        ->and($brand->reverb_app_key)->not->toBeNull()
        ->and($brand->domains()->pluck('host')->all())->toBe(['con.acme.test']);
});

test('install without brand flags creates no brand', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $arguments = installArguments();
    unset($arguments['--brand-name'], $arguments['--brand-domain']);

    $this->artisan('konventix:install', $arguments)->assertSuccessful();

    expect(Brand::query()->where('slug', 'acme-con')->exists())->toBeFalse();
});

test('install refuses to create the admin without a password in the environment', function () {
    $this->artisan('konventix:install', installArguments())->assertFailed();

    expect(User::query()->where('email', 'root@acme.test')->exists())->toBeFalse();
});

test('install refuses to run without the required options', function () {
    $this->artisan('konventix:install')->assertFailed();
});

test('install seeds the operator-wide checkout legal texts, and is idempotent about them (#200)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';

    $this->artisan('konventix:install', installArguments())->assertSuccessful();
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    // A fresh instance must never be able to sell without the checkout
    // resolving a terms and a withdrawal text: `konventix:seed` is a demo
    // command a real deployment never runs (#200).
    foreach (CheckoutLegalTexts::SLUGS as $slug) {
        $policy = Policy::withoutBrandScope()->whereNull('brand_id')->where('slug', $slug)->get();

        expect($policy)->toHaveCount(1)
            ->and($policy->first()->currentVersion())->not->toBeNull();
    }

    $brand = Brand::query()->firstWhere('slug', 'acme-con');

    expect(array_column(app(CheckoutLegalTexts::class)->forBrand($brand->getKey()), 'slug'))
        ->toBe(CheckoutLegalTexts::SLUGS);
});

/**
 * Whole-branch review finding 1. `konventix:install` is the command a
 * release runs, and #205 turned every published, effective, consent-requiring
 * contract version into a login gate. A placeholder that binds nobody must
 * therefore never be published as one: the first install after the release
 * would otherwise redirect every existing account on every brand to
 * `policies.required` and hold it there until it "agreed" to a text whose own
 * first line says it is not a legal document.
 */
test('the seeded placeholders leave an existing account\'s next login alone (#200, #205)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';

    // The dangerous run: an instance that already has accounts on it.
    $member = User::factory()->create();

    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    $this->withBrand();

    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('publishing the operator\'s real terms raises the login gate once (#200, #205)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $member = User::factory()->create();

    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    $this->withBrand();

    // What the operator does under *Policies* once the real AGB exists (#91).
    $terms = Policy::withoutBrandScope()->whereNull('brand_id')->where('slug', 'terms')->sole();
    $real = app(DraftPolicyVersion::class)($terms, new PolicyVersionData(
        version: 'agb-1',
        content: 'The operator\'s actual terms of participation.',
        requiresConsent: true,
        lawfulBasis: 'contract',
    ));
    app(PublishPolicyVersion::class)($real);

    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('policies.required', absolute: false));

    // Once: the consent recorded against that version silences the gate.
    Consent::factory()->for($real, 'policyVersion')->create(['subject_id' => $member->getKey()]);

    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});
