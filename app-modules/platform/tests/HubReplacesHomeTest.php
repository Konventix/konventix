<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Identity\Models\User;

// Renders `/` against a hub registry this file owns: which component the
// home route answers with is the subject, not what the four D-HUB-05
// launch contributions build — see `hubIsolatedRegistry()`.
beforeEach(fn () => hubIsolatedRegistry());

it('renders the hub at / for an admitted member when the switch is on, and the home for everyone else', function (): void {
    $brand = $this->withBrand();
    $admitted = User::factory()->create();
    $eventId = (string) Str::ulid();
    event(hubAdmitted($brand->id, $admitted->id, $eventId));
    event(new EventHubLayoutChanged($eventId, $brand->id, [], true));

    $this->actingAs($admitted)->get('/')->assertInertia(fn (Assert $p) => $p->component('platform/live/Hub'));
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p->component('Welcome'));

    // A real anonymous request. `actingAs` stays in the guard for every later
    // request in the same test and the scoped context survives a request
    // boundary here, so without these three lines the "guest" below would be
    // the member above, answered from a warm memo — and the query count
    // would say nothing.
    Auth::logout();
    $this->app->forgetScopedInstances();
    $this->withBrand($brand);

    DB::enableQueryLog();
    $this->get('/')->assertInertia(fn (Assert $p) => $p->component('Welcome'));
    expect(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'platform_hub')))->toHaveCount(0);
});

it('keeps the plain home reachable at /home', function (): void {
    $brand = $this->withBrand();
    $admitted = User::factory()->create();
    $eventId = (string) Str::ulid();
    event(hubAdmitted($brand->id, $admitted->id, $eventId));
    event(new EventHubLayoutChanged($eventId, $brand->id, [], true));

    $this->actingAs($admitted)->get('/home')->assertInertia(fn (Assert $p) => $p->component('Welcome'));
});

it('leaves / alone when the switch is off', function (): void {
    $brand = $this->withBrand();
    $admitted = User::factory()->create();
    event(hubAdmitted($brand->id, $admitted->id, (string) Str::ulid()));

    $this->actingAs($admitted)->get('/')->assertInertia(fn (Assert $p) => $p->component('Welcome'));
});
