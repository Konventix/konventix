<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;
use Konventix\Support\Queries\KnownIssues;

/**
 * The five triage writes of design §9.2, and the one thing they all share:
 * **every single one leaves an audit entry**.
 *
 * Three of them do it through a domain event that implements
 * `AuditableEvent`, which Compliance's wildcard subscriber records; the
 * other two — assignment and the external reference — have no event to
 * emit, because nothing outside this module could act on either and §10.4
 * fixes the public contract at four events. Those two are recorded
 * explicitly by the controller (Round 6, D-BATCH), and this file asserts
 * both halves the same way, because an operator reading the trail should
 * not be able to tell which mechanism wrote a line.
 */
beforeEach(function (): void {
    $this->operator = supportOperator('admin.support-triage.test');
    $this->brand = $this->withBrand();
    $this->brand->forceFill(['operator_id' => $this->operator->getKey()])->save();
    $this->admin = supportConsoleUser($this->operator);
    $this->request = SupportRequest::factory()
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Seen)
        ->create();
    $this->url = 'http://admin.support-triage.test/admin/support-requests/'.$this->request->id;
});

it('refuses every write without the triage capability', function (): void {
    $readOnly = supportConsoleUser($this->operator, ['support.view-requests'], 'Read Only');

    $this->actingAs($readOnly)
        ->patch($this->url.'/state', ['state' => 'in_progress'])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->patch($this->url.'/assignee', ['assignedToUserId' => null])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->patch($this->url.'/known-issue', ['isKnownIssue' => true])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->patch($this->url.'/external-reference', ['externalReference' => 'GL-1'])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->post($this->url.'/messages', ['visibility' => 'internal', 'body' => 'no'])
        ->assertForbidden();

    expect($this->request->refresh()->state)->toBe(SupportRequestState::Seen);
});

it('moves a report along the transition table and audits who did it', function (): void {
    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/state', ['state' => 'in_progress'])
        ->assertRedirect($this->url);

    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/state', ['state' => 'resolved'])
        ->assertRedirect($this->url);

    $request = $this->request->refresh();

    expect($request->state)->toBe(SupportRequestState::Resolved)
        ->and($request->resolved_at)->not->toBeNull()
        // The retention clock, and nothing else, reads this (D-SUP-11).
        ->and($request->closed_at)->not->toBeNull();

    $entries = AuditLogEntry::query()
        ->where('action', 'support.request.state-changed')
        ->orderBy('occurred_at')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->last()->context['from'])->toBe('in_progress')
        ->and($entries->last()->context['to'])->toBe('resolved')
        ->and($entries->last()->actor_user_id)->toBe($this->admin->id);
});

it('refuses a move the transition table forbids as a 422 on the state field', function (): void {
    $this->request->forceFill(['state' => SupportRequestState::Resolved])->save();

    // `resolved` has exactly one way back — `in_progress` — so a second
    // console trying to resolve it again from a stale page is the honest
    // way to reach this.
    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/state', ['state' => 'received'])
        ->assertSessionHasErrors('state');

    expect($this->request->refresh()->state)->toBe(SupportRequestState::Resolved)
        ->and(AuditLogEntry::query()->where('action', 'support.request.state-changed')->count())->toBe(0);
});

it('assigns a report to a triage holder and clears it again', function (): void {
    $triage = supportHolder($this->brand->id, [Permission::TriageRequests->value], 'Brand Desk');

    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/assignee', ['assignedToUserId' => $triage->id])
        ->assertRedirect($this->url);

    expect($this->request->refresh()->assigned_to_user_id)->toBe($triage->id);

    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/assignee', ['assignedToUserId' => null])
        ->assertRedirect($this->url);

    expect($this->request->refresh()->assigned_to_user_id)->toBeNull();

    $entries = AuditLogEntry::query()
        ->where('action', 'support.request.assigned')
        ->orderBy('occurred_at')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->first()->context['to'])->toBe($triage->id)
        ->and($entries->last()->context['to'])->toBeNull();
});

it('refuses an assignee who cannot triage reports filed here', function (): void {
    $stranger = supportHolder($this->brand->id, [Permission::ViewRequests->value], 'Brand Readers');

    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/assignee', ['assignedToUserId' => $stranger->id])
        ->assertSessionHasErrors('assignedToUserId');

    expect($this->request->refresh()->assigned_to_user_id)->toBeNull();
});

it('marks a report as a known issue, carries the notice and forgets the banner cache', function (): void {
    Cache::put(KnownIssues::cacheKey($this->brand->id), ['stale'], 60);

    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/known-issue', [
            'isKnownIssue' => true,
            'knownIssueNotice' => 'Paying by card is slow right now; it does go through.',
        ])
        ->assertRedirect($this->url);

    $request = $this->request->refresh();

    expect($request->is_known_issue)->toBeTrue()
        ->and($request->known_issue_notice)->toBe('Paying by card is slow right now; it does go through.')
        // A wrong banner has to come down at once, not within the minute.
        ->and(Cache::get(KnownIssues::cacheKey($this->brand->id)))->toBeNull();

    $entry = AuditLogEntry::query()->where('action', 'support.request.known-issue-marked')->sole();

    expect($entry->context['is_known_issue'])->toBeTrue()
        ->and($entry->actor_user_id)->toBe($this->admin->id);

    // Unmarking clears the notice: a sentence nobody can see will be wrong
    // by the time it is shown again.
    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/known-issue', ['isKnownIssue' => false])
        ->assertRedirect($this->url);

    expect($this->request->refresh()->known_issue_notice)->toBeNull();
});

it('stores the external reference as free text and clears it when blanked', function (): void {
    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/external-reference', ['externalReference' => '  GL-306  '])
        ->assertRedirect($this->url);

    expect($this->request->refresh()->external_reference)->toBe('GL-306');

    $this->actingAs($this->admin)
        ->from($this->url)
        ->patch($this->url.'/external-reference', ['externalReference' => ''])
        ->assertRedirect($this->url);

    expect($this->request->refresh()->external_reference)->toBeNull();

    expect(AuditLogEntry::query()->where('action', 'support.request.external-reference-set')->count())->toBe(2);
});

it('posts internal notes and external replies, and says which in the event', function (): void {
    $this->actingAs($this->admin)
        ->from($this->url)
        ->post($this->url.'/messages', [
            'visibility' => 'internal',
            'body' => 'Reproduced on staging; it is the payment page.',
        ])
        ->assertRedirect($this->url);

    $this->actingAs($this->admin)
        ->from($this->url)
        ->post($this->url.'/messages', [
            'visibility' => 'external',
            'body' => 'Thank you — we found it and a fix is on the way.',
        ])
        ->assertRedirect($this->url);

    $messages = SupportRequestMessage::query()
        ->where('support_request_id', $this->request->id)
        ->orderBy('created_at')
        ->get();

    expect($messages)->toHaveCount(2)
        ->and($messages->first()->visibility)->toBe(MessageVisibility::Internal)
        ->and($messages->first()->author_user_id)->toBe($this->admin->id)
        // The filter every reporter-facing query goes through.
        ->and(SupportRequestMessage::query()->visibleToReporter()->count())->toBe(1);

    $entries = AuditLogEntry::query()
        ->where('action', 'support.request.message-posted')
        ->orderBy('occurred_at')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('context.visibility')->all())->toBe(['internal', 'external'])
        // The body is deliberately absent from the event, so it cannot
        // reach the audit context or a future listener (§10.4).
        ->and($entries->first()->context)->not->toHaveKey('body');
});

it('demands a visibility rather than defaulting to one', function (): void {
    $this->actingAs($this->admin)
        ->from($this->url)
        ->post($this->url.'/messages', ['body' => 'who reads this?'])
        ->assertSessionHasErrors('visibility');

    expect(SupportRequestMessage::query()->count())->toBe(0);
});
