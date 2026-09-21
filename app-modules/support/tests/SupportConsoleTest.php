<?php

declare(strict_types=1);

use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Platform\Models\Brand;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;

/**
 * The console inbox and one report's detail page (design §9.1, §9.2).
 *
 * Two things here are worth stating out loud, because they are the reasons
 * this module looks different from every other console surface:
 *
 * - **The list spans brands, and includes the brandless rows.**
 *   `SupportRequest` carries no `BelongsToBrand`, so nothing has to escape
 *   `BrandScope` and nothing hardens to zero on the admin host (ADR-0029,
 *   ADR-0050 §1). A report filed on the operator console has no brand at
 *   all and still has to be findable — that case is asserted first.
 * - **Three capabilities, three different answers.** Reading the inbox,
 *   acting on a report and seeing who sent it are separate, and the last
 *   one is audited every single time (§10.8).
 */
beforeEach(function (): void {
    $this->operator = supportOperator('admin.support-console.test');
    $this->brand = $this->withBrand();
    $this->brand->forceFill(['operator_id' => $this->operator->getKey()])->save();
});

it('refuses the inbox to a console account without the view capability', function (): void {
    $admin = supportConsoleUser($this->operator, [], 'Console Only');

    $this->actingAs($admin)
        ->get('http://admin.support-console.test/admin/support-requests')
        ->assertForbidden();
});

it('lists reports of every brand and the brandless ones filed on the console', function (): void {
    $admin = supportConsoleUser($this->operator);

    $other = Brand::factory()->for($this->operator)->create();

    SupportRequest::factory()->filedOnBrand($this->brand->id)->create();
    SupportRequest::factory()->filedOnBrand($other->id)->create();
    // No brand at all: the case a global BrandScope would have hidden.
    SupportRequest::factory()->create();

    $this->actingAs($admin)
        ->get('http://admin.support-console.test/admin/support-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/support-requests/Index')
            ->has('requests.data', 3)
            ->where('requests.data', fn ($rows): bool => collect($rows)
                ->pluck('brandLabel')
                ->contains('Console')));
});

it('defaults to the open pile and shows everything only when the filter is cleared', function (): void {
    $admin = supportConsoleUser($this->operator);

    SupportRequest::factory()->filedOnBrand($this->brand->id)->create();
    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Resolved)
        ->create();

    $this->actingAs($admin)
        ->get('http://admin.support-console.test/admin/support-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests.data', 1));

    $this->actingAs($admin)
        ->get('http://admin.support-console.test/admin/support-requests?state=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests.data', 2));
});

it('filters by state, category, brand and the known-issue mark', function (): void {
    $admin = supportConsoleUser($this->operator);

    $purchase = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->categories([SupportCategory::PurchaseAffected])
        ->knownIssue('The payment page is slow right now.')
        ->create();

    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->categories([SupportCategory::Display])
        ->inState(SupportRequestState::InProgress)
        ->create();

    $console = SupportRequest::factory()->create();

    $get = fn (string $query) => $this->actingAs($admin)
        ->get('http://admin.support-console.test/admin/support-requests?'.$query)
        ->assertOk();

    $get('category=purchase_affected')
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $purchase->id));

    $get('state=in_progress')
        ->assertInertia(fn ($page) => $page->has('requests.data', 1));

    $get('brand=console')
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $console->id));

    $get('brand='.$this->brand->id)
        ->assertInertia(fn ($page) => $page->has('requests.data', 2));

    $get('known_issue=yes')
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.isKnownIssue', true));

    $get('known_issue=no')
        ->assertInertia(fn ($page) => $page->has('requests.data', 2));
});

it('finds a report by its reference, its message and its external reference', function (): void {
    $admin = supportConsoleUser($this->operator);

    $request = SupportRequest::factory()->filedOnBrand($this->brand->id)->create([
        'message' => 'The seat map shows nothing at all on my tablet.',
        'external_reference' => 'GL-4711',
    ]);
    SupportRequest::factory()->filedOnBrand($this->brand->id)->create([
        'message' => 'Everything is fine, just a suggestion about colours.',
    ]);

    foreach ([$request->reference(), 'seat map', 'GL-4711'] as $term) {
        $this->actingAs($admin)
            ->get('http://admin.support-console.test/admin/support-requests?search='.urlencode($term))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $request->id));
    }
});

it('never puts the reporter address in the inbox, however capable the reader is', function (): void {
    $admin = supportConsoleUser($this->operator);

    SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->byGuest('visitor@example.test')
        ->create();

    $response = $this->actingAs($admin)
        ->get('http://admin.support-console.test/admin/support-requests')
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->where('requests.data.0.reporterLabel', 'v***@example.test'));

    expect($response->getContent())->not->toContain('visitor@example.test');
});

it('moves a report to seen the first time the console opens it, once', function (): void {
    $admin = supportConsoleUser($this->operator);

    $request = SupportRequest::factory()->filedOnBrand($this->brand->id)->create();

    expect($request->seen_at)->toBeNull();

    $this->actingAs($admin)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/support-requests/Show')
            ->where('detail.request.state', 'seen'));

    $seenAt = $request->refresh()->seen_at;

    expect($seenAt)->not->toBeNull();

    // A second open is a no-op: the same state, no second event, and the
    // clock `support_requests_unseen_oldest_age_seconds` measures does not
    // restart.
    $this->travel(5)->minutes();

    $this->actingAs($admin)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk();

    expect($request->refresh()->seen_at->equalTo($seenAt))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'support.request.state-changed')->count())->toBe(1);
});

it('shows the unmasked contact only to a holder, and records every disclosure', function (): void {
    $request = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->byGuest('reporter@example.test')
        ->create();

    $withoutContact = supportConsoleUser(
        $this->operator,
        ['support.view-requests', 'support.triage-requests'],
        'No Contact',
    );

    $this->actingAs($withoutContact)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('detail.reporterContact', null));

    expect(AuditLogEntry::query()->where('action', 'support.reporter-contact.viewed')->count())->toBe(0);

    $withContact = supportConsoleUser($this->operator, roleName: 'Full Desk');

    $this->actingAs($withContact)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('detail.reporterContact', 'reporter@example.test'));

    $entry = AuditLogEntry::query()->where('action', 'support.reporter-contact.viewed')->sole();

    expect($entry->subject_id)->toBe($request->id)
        ->and($entry->actor_user_id)->toBe($withContact->id)
        ->and($entry->context['kind'])->toBe('email');
});

it('offers only the moves the transition table allows, and hides the controls without triage', function (): void {
    $request = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Resolved)
        ->create();

    $readOnly = supportConsoleUser($this->operator, ['support.view-requests'], 'Read Only');

    $this->actingAs($readOnly)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canTriage', false)
            // Resolved has exactly one way back (§4.3).
            ->where('detail.allowedNext', ['in_progress']));
});

it('builds the logs deep link from both correlation ids, and none without a dashboard', function (): void {
    $admin = supportConsoleUser($this->operator);

    $request = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->context(supportContextWithIds('page-01K5MZ6V4N8YQ', 'submit-01K5MZ6V4N'))
        ->create();

    // Unset by default: a link to nowhere is worse than no link, so the
    // console renders the ids without a button.
    $this->actingAs($admin)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('detail.pageLogsUrl', null)
            ->where('detail.submitLogsUrl', null));

    config()->set('support.logs.correlation_url', 'https://logs.example.test/?q=:correlationId');

    $this->actingAs($admin)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('detail.pageLogsUrl', 'https://logs.example.test/?q=page-01K5MZ6V4N8YQ')
            ->where('detail.submitLogsUrl', 'https://logs.example.test/?q=submit-01K5MZ6V4N'));

    // A template that forgot the placeholder would open the whole log
    // stream and look like it had filtered it.
    config()->set('support.logs.correlation_url', 'https://logs.example.test/');

    $this->actingAs($admin)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('detail.pageLogsUrl', null));
});

it('offers the triage holders of the report brand as assignees', function (): void {
    $admin = supportConsoleUser($this->operator);

    $triage = supportHolder($this->brand->id, [Permission::TriageRequests->value], 'Brand Desk');
    supportHolder($this->brand->id, [Permission::ViewRequests->value], 'Brand Readers');

    $request = SupportRequest::factory()->filedOnBrand($this->brand->id)->create();

    $this->actingAs($admin)
        ->get("http://admin.support-console.test/admin/support-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('assignees', [$triage->id]));
});
