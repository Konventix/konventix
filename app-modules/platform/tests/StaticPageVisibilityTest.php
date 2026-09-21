<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Enums\PageVisibility;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Pages\RegistrantAudience;

/**
 * Who may read a brand page (Task 20): `public` everyone, `members` a
 * signed-in account, `registrants` whoever the bound `RegistrantAudience`
 * says holds a confirmed registration for one of the brand's events. A
 * refusal on `/pages/{slug}` is a 404 — never a login redirect, which would
 * confirm the slug exists — and on `/` it is the Welcome fallback, because
 * the root of a site always answers.
 *
 * Registration binds the real audience against its own table; these tests
 * bind a stand-in so the matrix is Platform's alone, and unbind it to prove
 * the fail-closed default.
 */
function pageVisibilityAudience(bool $answer): void
{
    app()->instance(RegistrantAudience::class, new class($answer) implements RegistrantAudience
    {
        public function __construct(private readonly bool $answer) {}

        public function isRegistrantOf(string $brandId, string $userId): bool
        {
            return $this->answer;
        }
    });
}

it('serves a members page to a signed-in account and 404s a guest', function (): void {
    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'members-only', 'visibility' => PageVisibility::Members]);

    $this->get('/pages/members-only')->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get('/pages/members-only')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('StaticPage'));
});

it('serves a registrants page only to whoever the audience names', function (): void {
    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'attendees', 'visibility' => PageVisibility::Registrants]);

    // A guest is never a registrant, whatever the audience would say.
    pageVisibilityAudience(true);
    $this->get('/pages/attendees')->assertNotFound();

    $this->actingAs(User::factory()->create())->get('/pages/attendees')->assertOk();

    // A member without a confirmed registration: the audience says no.
    app()->forgetScopedInstances();
    pageVisibilityAudience(false);
    $this->actingAs(User::factory()->create())->get('/pages/attendees')->assertNotFound();
});

it('fails closed when no RegistrantAudience is bound', function (): void {
    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'attendees', 'visibility' => PageVisibility::Registrants]);

    app()->offsetUnset(RegistrantAudience::class);
    expect(app()->bound(RegistrantAudience::class))->toBeFalse();

    $this->actingAs(User::factory()->create())->get('/pages/attendees')->assertNotFound();
});

it('keeps a public page public', function (): void {
    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'open']);

    $this->get('/pages/open')->assertOk();
    $this->actingAs(User::factory()->create())->get('/pages/open')->assertOk();
});

it('falls back to Welcome on / for a viewer the home page refuses', function (): void {
    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'home', 'title' => 'Members Home', 'visibility' => PageVisibility::Members]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('StaticPage')->where('title', 'Members Home'));
});

it('renders a registrants home page for a registrant and Welcome for everyone else', function (): void {
    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'home', 'title' => 'Attendee Home', 'visibility' => PageVisibility::Registrants]);

    pageVisibilityAudience(true);
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('StaticPage')->where('title', 'Attendee Home'));

    app()->forgetScopedInstances();
    pageVisibilityAudience(false);
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});
