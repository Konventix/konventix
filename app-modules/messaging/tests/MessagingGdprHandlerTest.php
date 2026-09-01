<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Messaging\Gdpr\MessagingGdprHandler;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Suppression;

/**
 * The one handler ADR-0050 §3 hands a real email branch: suppressions and
 * the delivery log are keyed on the address, not an account, so a subject
 * with no account still gets a real answer here.
 */
it('exports suppressions and messages for an email-only subject', function (): void {
    $this->withBrand();

    $suppression = Suppression::factory()->create([
        'email' => 'blocked@example.de',
        'reason_code' => 'hard_bounce',
    ]);
    $message = MessageRecord::factory()->create(['recipient_email' => 'blocked@example.de']);

    // Neither belongs to the subject: a different address, and an
    // account-backed recipient.
    Suppression::factory()->create(['email' => 'someone-else@example.de']);
    MessageRecord::factory()->create(['recipient_email' => 'someone-else@example.de']);

    $export = (new MessagingGdprHandler)->exportFor(SubjectRef::forEmail('blocked@example.de'));

    expect($export['suppressions'])->toHaveCount(1)
        ->and($export['suppressions'][0]['reason'])->toBe('hard_bounce')
        ->and($export['messages'])->toHaveCount(1)
        ->and($export['messages'][0]['template'])->toBe($message->template_key);
});

it('scrubs the delivery log for an email-only subject but never clears the suppression address', function (): void {
    $this->withBrand();

    $suppression = Suppression::factory()->create(['email' => 'blocked@example.de']);
    $message = MessageRecord::factory()->create([
        'recipient_email' => 'blocked@example.de',
        'rendered_subject' => 'Hello',
        'rendered_body' => 'Body',
    ]);

    (new MessagingGdprHandler)->delete(SubjectRef::forEmail('blocked@example.de'), DeletionMode::Erase);

    expect($message->refresh()->recipient_email)->not->toBe('blocked@example.de')
        ->and($message->rendered_subject)->toBeNull()
        ->and($message->rendered_body)->toBeNull()
        // The suppression exists because the address said no — forgetting
        // it would start sending again, so its email is never touched.
        ->and($suppression->refresh()->email)->toBe('blocked@example.de');
});
