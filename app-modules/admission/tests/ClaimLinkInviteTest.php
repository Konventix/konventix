<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Actions\RevokeClaimLink;
use Konventix\Admission\Actions\SendClaimLink;
use Konventix\Admission\Data\ClaimLinkMailData;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\MediaItem;

/**
 * The invitation mail and the revocation (#292 §4.1, #16 point 1). The mail
 * goes to an *address*, since the invitee usually has no account yet, and
 * carries the event's banner as its hero. Helper names prefixed
 * (.ai/rules/tests.md).
 */
function inviteTestAdmission(string $eventId): Admission
{
    return Admission::factory()->forRegistration((string) Str::ulid())->create([
        'event_id' => $eventId,
        'holder_user_id' => null,
        'state' => AdmissionState::Issued,
        'role' => 'visitor',
        'buyer_user_id' => (string) Str::ulid(),
    ]);
}

it('mails a bound link to its address with the event, the inviter, the URL and the banner', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $banner = MediaItem::factory()->create(['brand_id' => $brand->id, 'alt_text' => 'The hall']);
    ticketEventSummary($brand, $eventId, ['banner_media_id' => $banner->id]);
    $manager = ticketPerson(['display_name' => 'Ada Lovelace']);
    $admission = inviteTestAdmission($eventId);
    $link = app(IssueAdmissionClaimLink::class)->handle($admission, 'friend@example.test');
    Queue::fake();

    $sent = app(SendClaimLink::class)->handle($link, $admission, $manager->id);

    expect($sent)->toBeTrue();

    Queue::assertPushed(SendQueuedMessage::class, function (SendQueuedMessage $job) use ($link, $banner): bool {
        $message = $job->message;
        /** @var ClaimLinkMailData $payload */
        $payload = $message->payload;

        expect($message->templateKey)->toBe('admission.claim-link')
            ->and($message->recipientEmail)->toBe('friend@example.test')
            ->and($message->recipientId)->toBeNull()
            ->and($payload->eventName)->toBe('Kon Fest 2026')
            ->and($payload->managerName)->toBe('Ada Lovelace')
            ->and($payload->role)->toBe('visitor')
            ->and($payload->claimUrl)->toContain($link->id)->toContain('signature=')
            ->and($payload->expiresAt)->toBeString()->not->toBeEmpty()
            ->and($message->heroImageUrl)->toContain($banner->path)
            ->and($message->heroImageAlt)->toBe('The hall');

        return true;
    });
});

it('sends nothing for an unbound link, which is a bearer link to copy', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = inviteTestAdmission($eventId);
    $link = app(IssueAdmissionClaimLink::class)->handle($admission, null);
    Queue::fake();

    expect(app(SendClaimLink::class)->handle($link, $admission, null))->toBeFalse();

    Queue::assertNothingPushed();
});

it('renders the template with and without an inviter name, directives on their own lines', function (): void {
    $brand = $this->withBrand();
    $renderer = app(TemplateRenderer::class);
    $envelope = fn (ClaimLinkMailData $payload, string $locale): SendMessageData => new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: $brand->id,
        recipientId: null,
        recipientEmail: 'friend@example.test',
        recipientLocale: $locale,
        templateKey: 'admission.claim-link',
        payload: $payload,
        channel: 'mail',
    );

    $withName = $renderer->render($envelope(new ClaimLinkMailData(
        eventName: 'Kon Fest 2026',
        managerName: 'Ada Lovelace',
        role: 'visitor',
        claimUrl: 'https://example.test/claim/x',
        expiresAt: '28.08.2026 18:00',
    ), 'en'));

    expect($withName->body)->toContain('Ada Lovelace has reserved a ticket for you — Kon Fest 2026.')
        ->toContain('href="https://example.test/claim/x"')
        ->not->toContain('@if')
        ->not->toContain('@endif');

    $anonymous = $renderer->render($envelope(new ClaimLinkMailData(
        eventName: null,
        managerName: null,
        role: 'visitor',
        claimUrl: 'https://example.test/claim/x',
        expiresAt: '28.08.2026 18:00',
    ), 'de'));

    expect($anonymous->body)->toContain('Ein Ticket wurde für Sie reserviert.')
        ->not->toContain('@');
});

it('revokes a live link and leaves a settled one alone', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = inviteTestAdmission($eventId);
    $link = app(IssueAdmissionClaimLink::class)->handle($admission, null);

    app(RevokeClaimLink::class)->handle($link);

    $revokedAt = $link->fresh()->revoked_at;
    expect($revokedAt)->not->toBeNull();

    $this->travel(1)->minutes();
    app(RevokeClaimLink::class)->handle($link->fresh());

    expect($link->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
});
