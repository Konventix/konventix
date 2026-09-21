<?php

declare(strict_types=1);

use DateTimeInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * A guest's own report, through the 30-day signed link in their receipt
 * (design §8.2).
 *
 * The signature is the whole authorization, so this file is mostly about
 * what happens when it is *not* good: an expired link, a tampered one, a
 * link to a report the retention sweep has taken. None of those may be a
 * bare 403 — the person holding that URL is somebody whose checkout broke
 * five weeks ago, not somebody probing a signature.
 *
 * And the re-issue endpoint's whole design is that it tells you nothing:
 * the same answer for a reference that exists, one that never did, and one
 * that belongs to an account. That is asserted twice, because it is the
 * property a well-meaning refactor removes first.
 */
beforeEach(function (): void {
    $this->brand = $this->withBrand();
});

function supportGuestLink(SupportRequest $request, ?DateTimeInterface $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'support.requests.show',
        $expiresAt ?? now()->addDays(30),
        ['supportRequest' => (string) $request->getKey()],
    );
}

function supportGuestReplyLink(SupportRequest $request, ?DateTimeInterface $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'support.requests.reply',
        $expiresAt ?? now()->addDays(30),
        ['supportRequest' => (string) $request->getKey()],
    );
}

/**
 * The brand id is passed rather than read off the TestCase: a plain
 * `function` in a Pest file runs in global scope and has no `$this`
 * (`.ai/rules/tests.md`).
 */
function supportGuestRequest(string $brandId, string $email = 'visitor@example.test'): SupportRequest
{
    return SupportRequest::factory()
        ->byGuest($email)
        ->filedOnBrand($brandId)
        ->inState(SupportRequestState::Seen)
        ->create();
}

it('shows the report on a valid signed link, with the external thread only', function (): void {
    $request = supportGuestRequest($this->brand->id);

    SupportRequestMessage::factory()->create([
        'support_request_id' => $request->id,
        'visibility' => MessageVisibility::External,
        'body' => 'We have reproduced it and are fixing it.',
    ]);

    SupportRequestMessage::factory()->create([
        'support_request_id' => $request->id,
        'visibility' => MessageVisibility::Internal,
        'body' => 'Third report of the same thing today.',
    ]);

    $this->get(supportGuestLink($request))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('support/requests/Show')
            ->where('isGuestLink', true)
            ->where('request.reference', $request->reference())
            ->has('request.messages', 1))
        ->assertDontSee('Third report of the same thing');
});

it('mints the reply URL with the expiry the visit arrived with, never a fresh 30 days', function (): void {
    $request = supportGuestRequest($this->brand->id);
    $expiresAt = now()->addDays(3);

    $this->get(supportGuestLink($request, $expiresAt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('replyUrl', fn (string $url): bool => str_contains(
                $url,
                'expires='.$expiresAt->getTimestamp(),
            )));
});

it('lands an expired link on the re-issue page rather than a 403', function (): void {
    $request = supportGuestRequest($this->brand->id);

    $this->get(supportGuestLink($request, now()->subMinute()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('support/requests/LinkExpired')
            ->where('reference', $request->reference()));
});

it('lands a tampered signature on the same page', function (): void {
    $request = supportGuestRequest($this->brand->id);
    $link = supportGuestLink($request);

    $tampered = (string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $link);

    $this->get($tampered)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('support/requests/LinkExpired'));
});

it('404s a valid signature over a report that is gone', function (): void {
    $request = supportGuestRequest($this->brand->id);
    $link = supportGuestLink($request);

    $request->delete();

    $this->get($link)->assertNotFound();
});

it('takes a reply on the signed link, as the reporter with no author id', function (): void {
    $request = supportGuestRequest($this->brand->id);
    $expiresAt = now()->addDays(30);

    $this->post(supportGuestReplyLink($request, $expiresAt), ['body' => 'It is still broken on Safari.'])
        ->assertRedirect();

    $message = SupportRequestMessage::query()->sole();

    expect($message->visibility)->toBe(MessageVisibility::External)
        // The null that already means "the reporter" on this table (§4.2).
        ->and($message->author_user_id)->toBeNull()
        ->and($message->body)->toContain('still broken on Safari');
});

it('refuses a reply on a terminal report', function (): void {
    $request = SupportRequest::factory()
        ->byGuest('visitor@example.test')
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::WontFix)
        ->create();

    $this->post(supportGuestReplyLink($request), ['body' => 'Please reconsider.'])
        ->assertSessionHasErrors('body');

    expect(SupportRequestMessage::query()->count())->toBe(0);
});

it('refuses an unsigned reply without reaching the controller', function (): void {
    $request = supportGuestRequest($this->brand->id);

    $this->post('/support/requests/'.$request->id.'/reply', ['body' => 'Let me in.'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('support/requests/LinkExpired'));

    expect(SupportRequestMessage::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Self-service re-issue
|--------------------------------------------------------------------------
*/

it('mails a fresh link to the stored address and never to a submitted one', function (): void {
    $request = supportGuestRequest($this->brand->id, 'Visitor@Example.TEST');
    Queue::fake();

    $this->postJson('/support/requests/link', [
        'reference' => $request->reference(),
        // Ignored entirely: the Data object has no such field, and the row
        // is the only source of the address.
        'email' => 'attacker@example.test',
    ])->assertStatus(202);

    $sends = supportQueuedSends('support.request-link');

    expect($sends)->toHaveCount(1);

    $message = $sends->first()->message;

    expect($message->recipientId)->toBeNull()
        ->and($message->recipientEmail)->toBe('visitor@example.test')
        ->and($message->topicKey)->toBeNull()
        ->and($message->brandId)->toBe($this->brand->id)
        ->and($message->payload->toArray()['reference'])->toBe($request->reference())
        ->and($message->payload->toArray()['requestUrl'])->toContain('signature=');
});

it('accepts a reference in any case and with the punctuation people add', function (): void {
    $request = supportGuestRequest($this->brand->id);
    Queue::fake();

    $this->postJson('/support/requests/link', [
        'reference' => Str::lower(Str::substr($request->reference(), 0, 4))
            .'-'.Str::lower(Str::substr($request->reference(), 4)),
    ])->assertStatus(202);

    expect(supportQueuedSends('support.request-link'))->toHaveCount(1);
});

it('answers a reference that names nothing exactly the same way', function (): void {
    supportGuestRequest($this->brand->id);
    Queue::fake();

    $this->postJson('/support/requests/link', ['reference' => 'ZZZZZZZZ'])
        ->assertStatus(202);

    expect(supportQueuedSends('support.request-link'))->toHaveCount(0);
});

it('sends nothing for a report an account filed, and says the same thing', function (): void {
    $request = SupportRequest::factory()
        ->byUser(User::factory()->create()->id)
        ->filedOnBrand($this->brand->id)
        ->create();

    Queue::fake();

    $this->postJson('/support/requests/link', ['reference' => $request->reference()])
        ->assertStatus(202);

    expect(supportQueuedSends('support.request-link'))->toHaveCount(0);
});

it('caps the re-issue at three an hour for one reference', function (): void {
    $request = supportGuestRequest($this->brand->id);
    Queue::fake();

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/support/requests/link', ['reference' => $request->reference()])
            ->assertStatus(202);
    }

    $this->postJson('/support/requests/link', ['reference' => $request->reference()])
        ->assertStatus(429);

    expect(supportQueuedSends('support.request-link'))->toHaveCount(3);
});
