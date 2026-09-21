<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;

/**
 * The console sidebar's open count (design §9.3, D-SUP-13).
 *
 * The badge is the first use of Platform's new per-item count, and the
 * interesting property is not the number — it is *when* the number is
 * worked out. Navigation is registered once at boot (ADR-0003) and the
 * registry is a singleton a long-lived Octane worker shares; a count
 * captured at registration would be frozen for the worker's whole life, the
 * same trap `.ai/rules/providers.md` describes for a scoped binding in a
 * singleton's constructor. So the entry carries a closure, and the second
 * test below is the one that would catch a regression: two requests, two
 * different answers, with nothing re-registered in between.
 */
beforeEach(function (): void {
    $this->operator = supportOperator('admin.support-badge.test');
    $this->brand = $this->withBrand();
    $this->brand->forceFill(['operator_id' => $this->operator->getKey()])->save();
    $this->admin = supportConsoleUser($this->operator);
});

it('counts nothing at boot', function (): void {
    SupportRequest::factory()->filedOnBrand($this->brand->id)->create();

    $entry = app(NavigationRegistry::class)
        ->all()
        ->first(fn (NavigationItem $item): bool => $item->module === 'support');

    expect($entry)->not->toBeNull()
        ->and($entry->url)->toBe('/admin/support-requests')
        ->and($entry->permission)->toBe('support.view-requests')
        ->and($entry->area)->toBe(NavigationItem::AREA_ADMIN)
        // A closure, not a number: the count belongs to a request.
        ->and($entry->badge)->toBeInstanceOf(Closure::class);
});

it('puts the brand-wide open count on the console entry', function (): void {
    // Two open on one brand, one open with no brand at all (filed on the
    // console, ADR-0063) and one already closed: the badge counts three.
    SupportRequest::factory()->filedOnBrand($this->brand->id)->count(2)->create();
    SupportRequest::factory()->create();
    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Resolved)
        ->create();

    $this->actingAs($this->admin)
        ->get('http://admin.support-badge.test/admin')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['admin']['navigation'])
                ->firstWhere('label', 'Support requests');

            expect($entry)->not->toBeNull()
                ->and($entry['badge'])->toBe(3);
        });
});

it('answers a different number on the next request without re-registering anything', function (): void {
    SupportRequest::factory()->filedOnBrand($this->brand->id)->create();

    $badge = fn (): ?int => collect(
        $this->actingAs($this->admin)
            ->get('http://admin.support-badge.test/admin')
            ->assertOk()
            ->viewData('page')['props']['admin']['navigation']
    )->firstWhere('label', 'Support requests')['badge'] ?? null;

    expect($badge())->toBe(1);

    SupportRequest::factory()->filedOnBrand($this->brand->id)->count(2)->create();

    expect($badge())->toBe(3);
});

it('carries no badge on an entry that declared none', function (): void {
    $entries = app(NavigationRegistry::class)
        ->all()
        ->filter(fn (NavigationItem $item): bool => $item->module !== 'support');

    expect($entries)->not->toBeEmpty()
        ->and($entries->every(fn (NavigationItem $item): bool => $item->toArray()['badge'] === null))
        ->toBeTrue();
});
