<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Identity\Models\User;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Models\ResourceAdmission;

/**
 * D-HUB-05: the hub is not empty on the first day. Four modules contribute
 * what an admitted participant reaches for first, and they contribute it
 * from their own providers (ADR-0003) — so the one thing worth pinning
 * centrally is that boot actually produced all four keys.
 *
 * `all()` rather than `visibleCards()`/`visibleLinks()`: two of the four
 * belong to gateable modules, and whether a brand has switched one on is
 * `HubRegistryTest`'s subject, not this one's.
 */
it('registers the four launch contributions', function (): void {
    $this->withBrand();

    $keys = app(HubRegistry::class)->all()->pluck('key')->all();

    expect($keys)->toContain('admission.my-ticket', 'content.announcements', 'resources.my-seat', 'support.help');
});

/**
 * A crew badge: an admission of this module's mirror with no registration
 * behind it (D-STF-01), which is why it can never have a seat claim.
 */
function hubCrewAdmission(Brand $brand, string $eventId, User $holder): ResourceAdmission
{
    $admission = new ResourceAdmission([
        'id' => (string) Str::ulid(),
        'registration_id' => null,
        'event_id' => $eventId,
        'holder_user_id' => (string) $holder->getKey(),
        'buyer_user_id' => null,
        'state' => ResourceAdmission::STATE_ASSIGNED,
    ]);
    $admission->forceFill(['brand_id' => $brand->getKey()])->save();

    return $admission;
}

/**
 * The hub's links as the page rendered them, keyed by their key.
 *
 * @return array<string, array<string, mixed>>
 */
function hubRenderedLinks(User $viewer): array
{
    $links = [];

    test()->actingAs($viewer)->get('/live')->assertInertia(function (Assert $page) use (&$links): void {
        /** @var list<array<string, mixed>> $rendered */
        $rendered = $page->toArray()['props']['hub']['links'];
        $links = collect($rendered)->keyBy('key')->all();
    });

    return $links;
}

/**
 * Resources' contribution is the one that depends on the *member*, not on
 * the brand: `My seat` names a seat claim behind the admission, and
 * a crew badge has no registration and therefore never a claim. The link
 * withdraws itself for them (`HubLink`) instead of offering the picker's
 * 404 — which is what it did before the whole-branch review.
 */
it('shows My seat to a member with a seat claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);

    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->getKey());
    $type = seatingCategory($eventId);
    $holder = User::factory()->create();
    $registrationId = (string) Str::ulid();
    seatingClaim($eventId, $registrationId, (string) $holder->getKey(), [$type->getKey()]);
    $admission = seatingAdmission($registrationId, $eventId, (string) $holder->getKey());

    event(hubAdmitted($brand->id, $holder->id, $eventId, admissionId: $admission->id));

    $links = hubRenderedLinks($holder);

    expect($links)->toHaveKey('resources.my-seat')
        ->and($links['resources.my-seat']['url'])->toBe('/my/admissions/'.$admission->id.'/seat')
        ->and($links['resources.my-seat']['label'])->toBe('My seat');
});

it('hides My seat from a crew admission, which can never have a claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);

    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->getKey());
    $crew = User::factory()->create();
    $admission = hubCrewAdmission($brand, $eventId, $crew);

    event(hubAdmitted($brand->id, $crew->id, $eventId, admissionId: $admission->id));

    $links = hubRenderedLinks($crew);

    // The rest of the row is still there — a withdrawn link costs its own
    // entry, not the page.
    expect($links)->not->toHaveKey('resources.my-seat')
        ->and($links)->toHaveKey('support.help');
});

/**
 * Support's is the other shape a link can take: no URL at all, a component
 * that opens the report dialog already on the page (ADR-0063 — the module
 * cannot be switched off, so every brand's hub carries it).
 */
it('renders Hilfe as a component rather than a URL', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    event(hubAdmitted($brand->id, $user->id, (string) Str::ulid()));

    $help = hubRenderedLinks($user)['support.help'] ?? null;

    expect($help)->not->toBeNull()
        ->and($help['url'])->toBeNull()
        ->and($help['component'])->toBe('support/hub/HelpLink')
        ->and($help['label'])->toBe('Help');
});
