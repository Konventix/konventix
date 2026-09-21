<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Konventix\Platform\Models\Brand;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Queries\KnownIssues;

/**
 * `GET /support/known-issues` (design §9.4) — the one thing this module
 * says to a stranger.
 *
 * Every assertion here is really the same assertion from a different angle:
 * **nothing about somebody else's report may leak**. The endpoint has no
 * authentication, so the answer is an opaque id and a sentence an operator
 * wrote, and never the reporter's own words, their address, the category
 * list, the state or a timestamp.
 *
 * The rest is the cache, which is what makes an unauthenticated endpoint on
 * every brand host affordable at all.
 */
beforeEach(function (): void {
    $this->brand = $this->withBrand();
});

it('answers a guest with the marked, still-open notices of this brand', function (): void {
    $marked = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->knownIssue('Paying by card is slow right now; it does go through.')
        ->create();

    // Not marked.
    SupportRequest::factory()->filedOnBrand($this->brand->id)->create();

    // Marked but resolved: resolving the canonical report takes the banner
    // down by itself, so nobody has to remember to unmark it.
    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->knownIssue('Already fixed.')
        ->inState(SupportRequestState::Resolved)
        ->create();

    // Marked on another brand: not this brand's business.
    $other = Brand::factory()->create();
    SupportRequest::factory()
        ->filedOnBrand($other->id)
        ->knownIssue('Another brand entirely.')
        ->create();

    $this->getJson('/support/known-issues')
        ->assertOk()
        ->assertExactJson(['knownIssues' => [[
            'id' => $marked->id,
            'notice' => 'Paying by card is slow right now; it does go through.',
        ]]]);
});

it('carries a brandless notice to every brand host, because it is operator-wide', function (): void {
    // Filed on the operator console: no brand at all (ADR-0063), which is
    // how "the payment provider is down" reaches every brand at once.
    $console = SupportRequest::factory()
        ->knownIssue('Our payment provider is having trouble; we are on it.')
        ->create();

    $this->getJson('/support/known-issues')
        ->assertOk()
        ->assertJsonPath('knownIssues.0.id', $console->id);
});

it('names the category rather than the reporter when no sentence was written yet', function (): void {
    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->categories([SupportCategory::PurchaseAffected])
        ->byGuest('reporter@example.test')
        ->create([
            'is_known_issue' => true,
            'known_issue_notice' => null,
            'message' => 'The pay button does nothing and I already tried twice.',
        ]);

    $response = $this->getJson('/support/known-issues')->assertOk();

    $response->assertJsonPath('knownIssues.0.notice', 'Purchase affected');

    expect($response->getContent())
        ->not->toContain('pay button')
        ->not->toContain('reporter@example.test');
});

it('leaks nothing beyond an id and a sentence', function (): void {
    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->byGuest('reporter@example.test')
        ->knownIssue('Seat maps are not loading.')
        ->create(['message' => 'the hall plan is empty on my phone']);

    $payload = $this->getJson('/support/known-issues')->assertOk()->json('knownIssues.0');

    expect(array_keys($payload))->toBe(['id', 'notice']);
});

it('serves the same answer for sixty seconds, per brand', function (): void {
    $marked = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->knownIssue('Seat maps are not loading.')
        ->create();

    $this->getJson('/support/known-issues')->assertOk()->assertJsonCount(1, 'knownIssues');

    // Straight to the column, so nothing invalidates the cache on the way.
    DB::table('support_requests')
        ->where('id', $marked->id)
        ->update(['is_known_issue' => false]);

    $this->getJson('/support/known-issues')
        ->assertOk()
        ->assertJsonCount(1, 'knownIssues');

    Cache::forget(KnownIssues::cacheKey($this->brand->id));

    $this->getJson('/support/known-issues')
        ->assertOk()
        ->assertJsonCount(0, 'knownIssues');
});

it('needs no account at all', function (): void {
    $this->assertGuest();

    $this->getJson('/support/known-issues')->assertOk();
});
