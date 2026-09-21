<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Data\SupportViewportData;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * The model half of S0 (design §4.1/§4.2). The two facts worth pinning are
 * the ones that differ from every other model in the codebase: this row is
 * **not** brand-scoped, and its human reference is derived rather than
 * stored.
 */
it('derives the human reference from the last eight characters of the key', function (): void {
    $request = SupportRequest::factory()->create();

    expect($request->reference())
        ->toBe(strtoupper(substr((string) $request->getKey(), -8)))
        ->toHaveLength(8);
});

/**
 * The single most surprising line in the module, so it is asserted rather
 * than only documented: `filed_on_brand_id` is provenance (ADR-0050 §1),
 * and a brand context must not hide the other brands' rows — nor the
 * console-filed ones, which have no brand at all (ADR-0063).
 */
it('is not brand scoped: a brand context hides no report', function (): void {
    $sxlan = $this->withBrand();
    $other = Brand::factory()->create();

    SupportRequest::factory()->filedOnBrand($sxlan->getKey())->create();
    SupportRequest::factory()->filedOnBrand($other->getKey())->create();
    SupportRequest::factory()->create();

    expect(SupportRequest::query()->count())->toBe(3)
        ->and(SupportRequest::query()->filedOnBrand($sxlan->getKey())->count())->toBe(1)
        // The console-filed case, which a BrandScope could not express at all.
        ->and(SupportRequest::query()->filedOnBrand(null)->count())->toBe(1);
});

it('counts received, seen and in progress as open', function (): void {
    foreach (SupportRequestState::cases() as $state) {
        SupportRequest::factory()->inState($state)->create();
    }

    expect(SupportRequest::query()->open()->count())->toBe(3);
});

it('round-trips the context snapshot through the jsonb column', function (): void {
    $context = new SupportContextData(
        pageCorrelationId: '01JBRK8Z3YQ1ABCD',
        submitCorrelationId: '01JBRK8Z3YQ1WXYZ',
        url: 'https://sxlan.test/shop?page=2',
        route: 'commerce.shop.index',
        previousUrl: 'https://sxlan.test/',
        previousCorrelationId: null,
        recentVisits: [],
        release: '0.7.0',
        commit: null,
        brand: 'sxlan',
        event: 'summer-lan-2026',
        userId: null,
        locale: 'de',
        timezone: 'Europe/Berlin',
        userAgent: 'Mozilla/5.0',
        viewport: new SupportViewportData(width: 1280, height: 800, dpr: 2.0),
        clientSentAt: '2026-09-21T10:00:00+00:00',
        receivedAt: '2026-09-21T10:00:01+00:00',
        lastClientError: null,
    );

    $request = SupportRequest::factory()->context($context)->create()->refresh();

    expect($request->context)->toBeInstanceOf(SupportContextData::class)
        ->and($request->context->pageCorrelationId)->toBe('01JBRK8Z3YQ1ABCD')
        ->and($request->context->viewport?->width)->toBe(1280)
        ->and($request->context->route)->toBe('commerce.shop.index');
});

it('reads the categories back as enum cases', function (): void {
    $request = SupportRequest::factory()
        ->categories([SupportCategory::PurchaseAffected, SupportCategory::Display])
        ->create()
        ->refresh();

    expect($request->categoryCases())->toBe([SupportCategory::PurchaseAffected, SupportCategory::Display]);
});

/**
 * Contact data is behind its own capability (§10.1), so every surface needs
 * something it may print without it.
 */
it('masks the reporter for surfaces without the contact capability', function (): void {
    // Addresses are stored lower-cased (design §4.1), so the mask is too.
    expect(SupportRequest::factory()->byGuest('Jane.Doe@Example.com')->create()->maskedReporter())
        ->toBe('j***@example.com')
        ->and(SupportRequest::factory()->byUser((string) Str::ulid())->create()->maskedReporter())
        ->toBe('Account');
});

it('keeps internal notes out of the reporter-visible thread', function (): void {
    $request = SupportRequest::factory()->create();

    SupportRequestMessage::factory()->for($request, 'request')->create();
    SupportRequestMessage::factory()->for($request, 'request')->internal()->create();

    expect($request->messages()->count())->toBe(2)
        ->and($request->messages()->visibleToReporter()->count())->toBe(1)
        ->and($request->messages()->visibleToReporter()->first()?->visibility)
        ->toBe(MessageVisibility::External);
});

/**
 * The thread is deleted with its request, which is what makes
 * `support:purge-requests` one delete rather than a walk (design §10.7).
 */
it('takes the thread with it when the request goes', function (): void {
    $request = SupportRequest::factory()->create();
    SupportRequestMessage::factory()->for($request, 'request')->count(2)->create();

    $request->delete();

    expect(SupportRequestMessage::query()->count())->toBe(0);
});
