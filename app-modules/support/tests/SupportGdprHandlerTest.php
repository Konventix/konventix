<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Data\SupportViewportData;
use Konventix\Support\Gdpr\SupportGdprHandler;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * Design §10.5, D-SUP-08. Two things are unusual about this handler and
 * both are asserted here: it answers for an **address-backed** subject,
 * which most modules cannot (ADR-0050 §3), and its deletion **keeps the
 * row** — the report is the operator's record that something was broken,
 * and what survives says nothing about who reported it.
 */
function supportContextWithDevice(): SupportContextData
{
    return new SupportContextData(
        pageCorrelationId: '01JBRK8Z3YQ1ABCD',
        submitCorrelationId: '01JBRK8Z3YQ1WXYZ',
        url: 'https://sxlan.test/shop',
        route: 'commerce.shop.index',
        previousUrl: null,
        previousCorrelationId: null,
        recentVisits: [],
        release: '0.7.0',
        commit: null,
        brand: 'sxlan',
        event: null,
        userId: null,
        locale: 'de',
        timezone: 'Europe/Berlin',
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
        viewport: new SupportViewportData(width: 1280, height: 800, dpr: 2.0),
        clientSentAt: null,
        receivedAt: null,
        lastClientError: 'TypeError: undefined is not a function',
    );
}

it('exports the reports of an account-backed subject', function (): void {
    $handler = new SupportGdprHandler;
    $userId = (string) Str::ulid();

    $mine = SupportRequest::factory()->byUser($userId)->create(['message' => 'The checkout would not load for me.']);
    SupportRequest::factory()->create();

    SupportRequestMessage::factory()->for($mine, 'request')->byUser($userId)->create(['body' => 'Still broken today.']);
    SupportRequestMessage::factory()->for($mine, 'request')->internal()->create(['body' => 'Looks like the PSP again.']);

    $export = $handler->exportFor(SubjectRef::forUser($userId));

    expect($export['support_requests'])->toHaveCount(1)
        ->and($export['support_requests'][0]['message'])->toBe('The checkout would not load for me.')
        ->and($export['support_requests'][0]['reference'])->toBe($mine->reference())
        // Their own messages, never the staff note beside them.
        ->and($export['support_requests'][0]['my_messages'])->toHaveCount(1)
        ->and($export['support_requests'][0]['my_messages'][0]['body'])->toBe('Still broken today.');
});

/**
 * The half most handlers cannot answer: a guest report is personal data
 * about somebody we never gave an account.
 */
it('exports the reports of an e-mail-only subject', function (): void {
    $handler = new SupportGdprHandler;

    SupportRequest::factory()->byGuest('guest@example.com')->create(['message' => 'The ticket PDF is empty.']);
    SupportRequest::factory()->byGuest('somebody-else@example.com')->create();

    $export = $handler->exportFor(SubjectRef::forEmail('Guest@Example.com'));

    expect($export['support_requests'])->toHaveCount(1)
        ->and($export['support_requests'][0]['message'])->toBe('The ticket PDF is empty.');
});

it('leaves the user agent out of the export', function (): void {
    SupportRequest::factory()->byGuest('guest@example.com')->context(supportContextWithDevice())->create();

    $export = (new SupportGdprHandler)->exportFor(SubjectRef::forEmail('guest@example.com'));

    expect($export['support_requests'][0]['context'])
        ->not->toHaveKey('userAgent')
        ->toHaveKey('submitCorrelationId');
});

it('answers nothing for a subject with no reports', function (): void {
    expect((new SupportGdprHandler)->exportFor(SubjectRef::forUser((string) Str::ulid())))->toBe([]);
});

it('scrubs the person and keeps the incident record', function (): void {
    $handler = new SupportGdprHandler;
    $userId = (string) Str::ulid();

    $request = SupportRequest::factory()
        ->byUser($userId)
        ->context(supportContextWithDevice())
        ->create(['message' => 'My name is on the badge and it is wrong.']);

    $mine = SupportRequestMessage::factory()->for($request, 'request')->byUser($userId)->create();
    $staff = SupportRequestMessage::factory()->for($request, 'request')->internal()->create(['body' => 'Reprinted.']);

    $handler->delete(SubjectRef::forUser($userId), DeletionMode::Erase);

    $request->refresh();

    expect(SupportRequest::query()->whereKey($request->getKey())->exists())->toBeTrue()
        ->and($request->user_id)->toBeNull()
        ->and($request->email)->toBeNull()
        ->and($request->message)->toBe(SupportGdprHandler::REMOVED)
        ->and($mine->refresh()->body)->toBe(SupportGdprHandler::REMOVED)
        // The operator's own handling is not the person's data.
        ->and($staff->refresh()->body)->toBe('Reprinted.')
        // What described the device goes...
        ->and($request->context->userAgent)->toBeNull()
        ->and($request->context->viewport)->toBeNull()
        ->and($request->context->lastClientError)->toBeNull()
        ->and($request->context->recentVisits)->toBe([])
        // ...what describes the incident stays.
        ->and($request->context->submitCorrelationId)->toBe('01JBRK8Z3YQ1WXYZ')
        ->and($request->context->route)->toBe('commerce.shop.index')
        ->and($request->state)->not->toBeNull();
});

it('scrubs a guest reporter by address, including their own replies', function (): void {
    $request = SupportRequest::factory()->byGuest('guest@example.com')->create();
    $reporterReply = SupportRequestMessage::factory()->for($request, 'request')->byReporter()->create(['body' => 'Any news?']);
    $staffReply = SupportRequestMessage::factory()->for($request, 'request')->byUser((string) Str::ulid())->create(['body' => 'We are on it.']);

    (new SupportGdprHandler)->delete(SubjectRef::forEmail('guest@example.com'), DeletionMode::Anonymize);

    expect($request->refresh()->email)->toBeNull()
        ->and($reporterReply->refresh()->body)->toBe(SupportGdprHandler::REMOVED)
        ->and($staffReply->refresh()->body)->toBe('We are on it.');
});

it('is idempotent: a second deletion changes nothing', function (): void {
    $handler = new SupportGdprHandler;
    $userId = (string) Str::ulid();
    SupportRequest::factory()->byUser($userId)->create();

    $handler->delete(SubjectRef::forUser($userId), DeletionMode::Erase);
    // The second run finds no row for the subject at all, because the
    // handle it matched on is exactly what the first run removed.
    $handler->delete(SubjectRef::forUser($userId), DeletionMode::Erase);

    expect(SupportRequest::query()->count())->toBe(1)
        ->and(SupportRequest::query()->first()?->message)->toBe(SupportGdprHandler::REMOVED);
});

it('names itself with the module key the registry uses', function (): void {
    expect((new SupportGdprHandler)->module())->toBe('support');
});
