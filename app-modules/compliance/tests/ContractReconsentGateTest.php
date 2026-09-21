<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Events\ConsentGranted;
use Konventix\Compliance\Events\ConsentPromptRaised;
use Konventix\Compliance\Http\Middleware\EnsureContractConsent;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;

/**
 * The mid-session re-consent gate (ADR-0066 §6.5, D-CPL-12/13/14) — the
 * narrow second alias `require-contract-consent`, and the interstitial it
 * lands on.
 *
 * Three properties this file exists to keep true, because each of them is a
 * way the feature stops a sale without anything else noticing:
 *
 * - the gate asks the **narrow** question. A consent-based required text is
 *   the wider alias's business and must not stand in front of a contract;
 * - it is never applied to the pages somebody needs in order to *get out* of
 *   it — `policies.*`, the login routes, `/my/*` or Support's report channel
 *   (ADR-0063, T-9). A gate in front of the channel by which somebody
 *   reports that the gate is broken is the worst possible loop;
 * - "Not now" is a real way out and **increments no counter of its own**
 *   (D-CPL-17). The refusal rate is derived from prompts against grants.
 */
function contractGateVersion(bool $contract = true): PolicyVersion
{
    $policy = Policy::factory()->create(['slug' => $contract ? 'terms' : 'photos', 'name' => $contract ? 'Terms' : 'Photo consent']);

    return PolicyVersion::factory()->for($policy)->published()->create([
        'requires_consent' => true,
        'lawful_basis' => $contract ? LawfulBasis::Contract : LawfulBasis::Consent,
    ]);
}

/**
 * A route standing in for a contract-creating one. The gate is opt-in per
 * route (ADR-0003), so the test opts a route in exactly as a module does.
 */
function contractGateFixtureRoutes(): void
{
    Route::middleware(['web', 'auth', 'require-contract-consent:your registration'])
        ->get('/contract-fixture', fn (): string => 'through')
        ->name('contract.fixture');

    Route::middleware(['web', 'auth', 'require-contract-consent:your registration'])
        ->post('/contract-fixture', fn (): string => 'posted')
        ->name('contract.fixture.store');
}

it('blocks a contract-outstanding subject on a contract-creating route', function (): void {
    $this->withBrand();
    contractGateVersion();
    contractGateFixtureRoutes();

    $user = User::factory()->create();

    Event::fake([ConsentPromptRaised::class]);

    $this->actingAs($user)
        ->get('http://localhost/contract-fixture')
        ->assertRedirect(route('policies.required', absolute: false));

    Event::assertDispatched(ConsentPromptRaised::class, fn (ConsentPromptRaised $event): bool => $event->surface === ConsentPromptRaised::SURFACE_ROUTE_GATE
        && $event->reason === ConsentPromptRaised::REASON_CONTRACT
        && $event->subjectId === (string) $user->getKey());
});

it('lets a subject through whose only outstanding text is consent-based', function (): void {
    $this->withBrand();
    contractGateVersion(contract: false);
    contractGateFixtureRoutes();

    $this->actingAs(User::factory()->create())
        ->get('http://localhost/contract-fixture')
        ->assertOk()
        ->assertSee('through');
});

it('lets a guest through the contract gate untouched', function (): void {
    $this->withBrand();
    contractGateVersion();

    Route::middleware(['web', 'require-contract-consent'])
        ->get('/contract-open-fixture', fn (): string => 'through');

    $this->get('http://localhost/contract-open-fixture')->assertOk()->assertSee('through');
});

it('remembers where to return on a GET and never on a POST', function (): void {
    $this->withBrand();
    $version = contractGateVersion();
    contractGateFixtureRoutes();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://localhost/contract-fixture')
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', 'http://localhost/contract-fixture');

    $this->flushSession();

    $this->actingAs($user)
        ->post('http://localhost/contract-fixture')
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionMissing('url.intended');

    // And agreeing lands where the GET was going.
    $this->flushSession();

    $this->actingAs($user)->get('http://localhost/contract-fixture');

    $this->actingAs($user)
        ->post('http://localhost/consents', ['versionIds' => [$version->id]])
        ->assertRedirect('http://localhost/contract-fixture');
});

it('names the act on the interstitial and offers a way out that counts nothing', function (): void {
    $this->withBrand();
    contractGateVersion();
    contractGateFixtureRoutes();

    $user = User::factory()->create();

    $this->actingAs($user)->get('http://localhost/contract-fixture');

    Event::fake([ConsentPromptRaised::class, ConsentGranted::class]);

    $this->actingAs($user)
        ->get('http://localhost/policies-required')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('policies/Required')
            ->where('actLabel', 'your registration')
            ->where('notNowUrl', '/')
            ->has('versions', 1));

    // "Not now" is a link away from the act. Nothing is recorded, nothing is
    // counted — neither a prompt nor a grant (D-CPL-17).
    $this->actingAs($user)->get('http://localhost/');

    Event::assertNotDispatched(ConsentGranted::class);
    Event::assertNotDispatched(ConsentPromptRaised::class);

    expect(Consent::withoutBrandScope()->count())->toBe(0);
});

it('forgets the act label once the agreement is recorded', function (): void {
    $this->withBrand();
    $version = contractGateVersion();
    contractGateFixtureRoutes();

    $user = User::factory()->create();

    $this->actingAs($user)->get('http://localhost/contract-fixture');

    $this->actingAs($user)
        ->post('http://localhost/consents', ['versionIds' => [$version->id]])
        ->assertSessionMissing(EnsureContractConsent::ACT_SESSION_KEY);
});

it('carries the alias on every contract-creating route and on none of the escape hatches', function (): void {
    $gated = [
        'registration.show',
        'registration.store',
        'registration.waitlist.claim',
        'commerce.cart.choose',
        'commerce.cart.confirm',
        'commerce.cart.checkout',
        'commerce.pay.choose',
        'commerce.pay.confirm',
        'commerce.pay.start',
        'commerce.pay.order.choose',
        'commerce.pay.order.confirm',
        'commerce.pay.order.start',
        'admission.claim.show',
        'admission.claim',
    ];

    // T-9, ADR-0063: the ways out of the gate, and the surfaces that are a
    // person reading back what they already owe rather than owing more.
    $open = [
        'policies.show',
        'policies.required',
        'consents.store',
        'consents.destroy',
        'login',
        'login.store',
        'logout',
        'registration.mine.index',
        'registration.mine.show',
        'commerce.mine.index',
        'commerce.mine.show',
        'commerce.cart.show',
        'commerce.pay.show',
        'commerce.pay.order.show',
        'admission.tickets.mine',
        'support.requests.store',
    ];

    // Compared as lists of route names rather than one assertion per
    // route: a failure then says *which* route lost or gained the gate.
    $ungated = array_values(array_filter(
        $gated,
        fn (string $name): bool => ! in_array('require-contract-consent', contractGateMiddlewareOf($name), true),
    ));

    $leaked = array_values(array_filter(
        $open,
        fn (string $name): bool => in_array('require-contract-consent', contractGateMiddlewareOf($name), true),
    ));

    expect($ungated)->toBe([])->and($leaked)->toBe([]);
});

/**
 * The middleware a named route carries, as the aliases were written — the
 * route's own list, not the resolved classes, because the alias string is
 * what a module writes and what this test is about.
 *
 * @return list<string>
 */
function contractGateMiddlewareOf(string $name): array
{
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();

    return array_values(array_map(
        static fn (string $middleware): string => explode(':', $middleware)[0],
        $route->gatherMiddleware(),
    ));
}
