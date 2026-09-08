<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Konventix\Compliance\Actions\DraftPolicyVersion;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Identity\Models\Session;
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

test('a re-run leaves an existing admin\'s password alone and names the flag that would change it (#223)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    // The deployment's secret was rotated; the chart runs the install again
    // on the next release. Silently adopting the new value would let anyone
    // who can edit the secret take over the console (#223), so the default
    // stays "creates what is missing" (ADR-0045).
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'rotated-secret';

    $this->artisan('konventix:install', installArguments())
        ->expectsOutputToContain('--reset-admin-password')
        ->assertSuccessful();

    $admin = User::query()->firstWhere('email', 'root@acme.test');
    expect(Hash::check('first-run-secret', $admin->password))->toBeTrue()
        ->and(Hash::check('rotated-secret', $admin->password))->toBeFalse();
});

test('--reset-admin-password sets the existing admin\'s password and says whose it was (#223)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'rotated-secret';

    // The recovery path #223 asks for: an operator locked out of DEV runs the
    // install once with the flag and the secret becomes true again. The
    // account it applied to is printed, because --admin-email is the only
    // thing that decides it and a typo there is otherwise invisible.
    $this->artisan('konventix:install', [...installArguments(), '--reset-admin-password' => true])
        ->expectsOutputToContain('root@acme.test')
        ->assertSuccessful();

    $admin = User::query()->firstWhere('email', 'root@acme.test');
    expect(Hash::check('rotated-secret', $admin->password))->toBeTrue();
});

test('--reset-admin-password rotates the remember token and ends every live session (#223)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    $admin = User::query()->firstWhere('email', 'root@acme.test');
    $admin->forceFill(['remember_token' => 'a-stolen-cookie'])->save();

    Session::query()->create([
        'id' => 'a-live-session',
        'user_id' => $admin->getKey(),
        'ip_address' => '203.0.113.7',
        'user_agent' => 'probe',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    // Somebody else's session, to prove the reset is aimed rather than broad.
    $bystander = User::factory()->create();
    Session::query()->create([
        'id' => 'a-bystanders-session',
        'user_id' => $bystander->getKey(),
        'ip_address' => '203.0.113.8',
        'user_agent' => 'probe',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'rotated-secret';

    // The flag exists for the case where the secret no longer opens the
    // console, which usually means it was rotated *because* it leaked. A new
    // hash alone leaves a stolen remember-me cookie and any live session
    // working, so the reset takes those away too.
    $this->artisan('konventix:install', [...installArguments(), '--reset-admin-password' => true])
        ->assertSuccessful();

    expect($admin->refresh()->remember_token)->not->toBe('a-stolen-cookie')
        ->and(Session::query()->whereKey('a-live-session')->exists())->toBeFalse()
        ->and(Session::query()->whereKey('a-bystanders-session')->exists())->toBeTrue();
});

test('--reset-admin-password without a password in the environment changes nothing (#223)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    unset($_ENV['KONVENTIX_ADMIN_PASSWORD']);

    // A flag with no secret behind it is a mistake, not an instruction to
    // blank the password.
    $this->artisan('konventix:install', [...installArguments(), '--reset-admin-password' => true])
        ->assertFailed();

    $admin = User::query()->firstWhere('email', 'root@acme.test');
    expect(Hash::check('first-run-secret', $admin->password))->toBeTrue();
});

/**
 * Every slug `OperatorLegalTextsSeeder` publishes, not only the two the
 * checkout resolves: `privacy` joined the pair in #184 and the idempotency
 * assertions have to cover it too (whole-branch review finding 8).
 *
 * @return list<string>
 */
function installedLegalTextSlugs(): array
{
    return [...CheckoutLegalTexts::SLUGS, 'privacy'];
}

test('install seeds the operator-wide checkout legal texts, and is idempotent about them (#200)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';

    $this->artisan('konventix:install', installArguments())->assertSuccessful();
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    // A fresh instance must never be able to sell without the checkout
    // resolving a terms and a withdrawal text: `konventix:seed` is a demo
    // command a real deployment never runs (#200).
    foreach (installedLegalTextSlugs() as $slug) {
        $policies = Policy::withoutBrandScope()->whereNull('brand_id')->where('slug', $slug)->get();

        // Counting *versions* is what catches a broken seeder: a second
        // published placeholder leaves the policy count at 1 and
        // `currentVersion()` non-null, so the collection assertion alone
        // passes over the very duplicate the double run exists to detect
        // (`OperatorLegalTextsSeeder::seed()`'s existing-version guard).
        expect($policies)->toHaveCount(1)
            ->and($policies->first()->currentVersion())->not->toBeNull()
            ->and(PolicyVersion::withoutBrandScope()->where('policy_id', $policies->first()->getKey())->count())
            ->toBe(1);
    }

    $brand = Brand::query()->firstWhere('slug', 'acme-con');

    expect(array_column(app(CheckoutLegalTexts::class)->forBrand($brand->getKey()), 'slug'))
        ->toBe(CheckoutLegalTexts::SLUGS);
});

test('a release install leaves an operator\'s own edit of a seeded text alone (#200)', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';

    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    // The property that matters on the second release: the operator has
    // replaced the placeholder, and the install must not write over it.
    foreach (installedLegalTextSlugs() as $slug) {
        $policy = Policy::withoutBrandScope()->whereNull('brand_id')->where('slug', $slug)->sole();
        PolicyVersion::withoutBrandScope()
            ->where('policy_id', $policy->getKey())
            ->sole()
            ->forceFill(['content' => "The operator's own {$slug} text."])
            ->save();
    }

    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    foreach (installedLegalTextSlugs() as $slug) {
        $policy = Policy::withoutBrandScope()->whereNull('brand_id')->where('slug', $slug)->sole();
        $versions = PolicyVersion::withoutBrandScope()->where('policy_id', $policy->getKey())->get();

        expect($versions)->toHaveCount(1)
            ->and($versions->first()->content)->toBe("The operator's own {$slug} text.");
    }
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
    app(PublishPolicyVersion::class)($real, now());

    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('policies.required', absolute: false));

    // Once: the consent recorded against that version silences the gate.
    Consent::factory()->for($real, 'policyVersion')->create(['subject_id' => $member->getKey()]);

    $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});
