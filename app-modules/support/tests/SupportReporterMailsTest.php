<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Konventix\Support\Actions\PostReporterReply;
use Konventix\Support\Actions\PostSupportMessage;
use Konventix\Support\Actions\TransitionSupportRequest;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;

/**
 * What a reporter is told after they have filed (design §7, S3), and the
 * one thing that decides it: `contact_consent`.
 *
 * The receipt is the exception and is not tested here — it goes out
 * whatever the checkbox said, because for a guest it *is* the access
 * (`SupportNotificationsTest`). Everything after it is gated, and this file
 * asserts that gate from both sides for both update mails, because the
 * failure mode is silent: a consent check that stopped working would look
 * exactly like a working one to everybody except the person who asked not
 * to be written to.
 *
 * The other half is the direction of a thread. An operator's reply is news
 * to the reporter; the reporter's own reply is news to the operators and to
 * nobody else. Both hang off one event and split on who wrote the entry.
 */
beforeEach(function (): void {
    $this->operator = supportOperator('admin.support-updates.test');
    $this->brand = $this->withBrand();
    $this->brand->forceFill(['operator_id' => $this->operator->getKey()])->save();
});

it('mails a consenting reporter when their report moves', function (): void {
    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Seen)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::InProgress, null);

    $sends = supportQueuedSends('support.request-state-changed');

    expect($sends)->toHaveCount(1);

    $message = $sends->first()->message;
    $payload = $message->payload->toArray();

    expect($message->recipientId)->toBeNull()
        ->and($message->recipientEmail)->toBe('visitor@example.test')
        // A guest has no account to hold a preference, so no topic.
        ->and($message->topicKey)->toBeNull()
        ->and($payload['reference'])->toBe($request->reference())
        ->and($payload['stateEn'])->toBe('In progress')
        ->and($payload['noteEn'])->toContain('working on it')
        ->and($payload['requestUrl'])->toContain('signature=');
});

it('tells a consenting account on their own topic', function (): void {
    $reporter = supportRecipient($this->brand->id);

    $request = SupportRequest::factory()
        ->byUser($reporter->id)
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Resolved, null);

    $sends = supportQueuedSends('support.request-state-changed');

    // The topic's two default channels each produce their own envelope.
    expect($sends)->toHaveCount(2);

    $message = $sends->first()->message;

    expect($message->recipientId)->toBe($reporter->id)
        ->and($message->topicKey)->toBe('support.request-updates')
        ->and($message->payload->toArray()['requestUrl'])
        ->toContain('/my/support-requests/'.$request->getKey());
});

it('says nothing about a state change without consent', function (): void {
    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Seen)
        ->create(['contact_consent' => false]);

    Queue::fake();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Resolved, null);

    expect(supportQueuedSends('support.request-state-changed'))->toHaveCount(0);
});

/**
 * `seen` is the automatic move the first console open makes, often seconds
 * after the receipt landed. Mailing it would teach every reporter that this
 * module's mails are noise.
 */
it('says nothing when a report is merely seen', function (): void {
    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(TransitionSupportRequest::class)->handle($request, SupportRequestState::Seen, null);

    expect(supportQueuedSends('support.request-state-changed'))->toHaveCount(0);
});

it('mails a consenting reporter an operator’s external reply', function (): void {
    $operatorUser = supportHolder($this->brand->id, [Permission::TriageRequests->value]);

    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(PostSupportMessage::class)->handle(
        $request,
        MessageVisibility::External,
        'We found the cause and a fix goes out tonight.',
        $operatorUser->id,
    );

    $sends = supportQueuedSends('support.request-replied');

    expect($sends)->toHaveCount(1)
        ->and($sends->first()->message->recipientEmail)->toBe('visitor@example.test')
        ->and($sends->first()->message->payload->toArray()['excerpt'])
        ->toContain('a fix goes out tonight');
});

it('says nothing about a reply without consent', function (): void {
    $operatorUser = supportHolder($this->brand->id, [Permission::TriageRequests->value]);

    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create(['contact_consent' => false]);

    Queue::fake();

    app(PostSupportMessage::class)->handle(
        $request,
        MessageVisibility::External,
        'We found the cause.',
        $operatorUser->id,
    );

    expect(supportQueuedSends('support.request-replied'))->toHaveCount(0);
});

it('never mails a reporter about an internal note, consent or not', function (): void {
    $operatorUser = supportHolder($this->brand->id, [Permission::TriageRequests->value]);

    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(PostSupportMessage::class)->handle(
        $request,
        MessageVisibility::Internal,
        'Third report of the same thing today.',
        $operatorUser->id,
    );

    expect(supportQueuedSends('support.request-replied'))->toHaveCount(0)
        ->and(supportQueuedSends('support.request-notice'))->toHaveCount(0);
});

it('mails nobody the reporter when the reporter is the one who wrote, and tells the operators instead', function (): void {
    $triage = supportHolder($this->brand->id, [Permission::TriageRequests->value]);

    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(PostReporterReply::class)->handle($request, 'It still happens on Safari.', null);

    expect(supportQueuedSends('support.request-replied'))->toHaveCount(0);

    $notices = supportQueuedSends('support.request-notice');

    // The operator topic's two default channels, one recipient.
    expect($notices)->toHaveCount(2)
        ->and($notices->pluck('message.recipientId')->unique()->all())->toBe([$triage->id]);

    $payload = $notices->first()->message->payload->toArray();

    expect($notices->first()->message->topicKey)->toBe('support.requests')
        ->and($notices->first()->message->urgent)->toBeTrue()
        ->and($payload['title'])->toContain($request->reference())
        ->and($payload['title'])->toContain('New reply')
        ->and($payload['detail'])->toContain('still happens on Safari')
        ->and($payload['url'])->toContain('/admin/support-requests/'.$request->getKey());
});

it('treats an account reporter’s own reply the same way', function (): void {
    $triage = supportHolder($this->brand->id, [Permission::TriageRequests->value]);
    $reporter = supportRecipient($this->brand->id);

    $request = SupportRequest::factory()
        ->byUser($reporter->id)
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create(['contact_consent' => true]);

    Queue::fake();

    app(PostReporterReply::class)->handle($request, 'Still broken.', $reporter->id);

    expect(supportQueuedSends('support.request-replied'))->toHaveCount(0)
        ->and(supportQueuedSends('support.request-notice')->pluck('message.recipientId')->unique()->all())
        ->toBe([$triage->id]);
});
