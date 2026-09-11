<?php

declare(strict_types=1);

use Konventix\Messaging\Data\AttachmentData;

/**
 * Spec §6.3: the inline form exists so a calendar file — derived, tiny,
 * regenerable — never touches a disk, while the disk form (Commerce's
 * invoice) stays the only way to attach a document. Exactly one form must
 * be set; the constructor is the enforcement point, not a caller
 * convention, so a half-specified attachment fails here rather than only
 * once `TemplatedMail::attachments()` tries to read it.
 */
it('accepts the disk form alone', function (): void {
    $attachment = new AttachmentData(as: 'invoice.pdf', disk: 'invoices', path: 'invoices/INV-1.pdf');

    expect($attachment->disk)->toBe('invoices')
        ->and($attachment->path)->toBe('invoices/INV-1.pdf')
        ->and($attachment->content)->toBeNull();
});

it('accepts the inline form alone', function (): void {
    $attachment = new AttachmentData(as: 'event.ics', content: 'BEGIN:VCALENDAR', mime: 'text/calendar');

    expect($attachment->content)->toBe('BEGIN:VCALENDAR')
        ->and($attachment->disk)->toBeNull()
        ->and($attachment->path)->toBeNull();
});

it('refuses both the disk form and the inline form at once', function (): void {
    new AttachmentData(as: 'event.ics', disk: 'invoices', path: 'invoices/INV-1.pdf', content: 'BEGIN:VCALENDAR');
})->throws(InvalidArgumentException::class);

it('refuses neither form being set', function (): void {
    new AttachmentData(as: 'event.ics');
})->throws(InvalidArgumentException::class);

it('refuses inline content over the 64 KiB ceiling', function (): void {
    new AttachmentData(as: 'event.ics', content: str_repeat('a', AttachmentData::MAX_INLINE_BYTES + 1));
})->throws(InvalidArgumentException::class);

it('accepts inline content exactly at the 64 KiB ceiling', function (): void {
    $attachment = new AttachmentData(as: 'event.ics', content: str_repeat('a', AttachmentData::MAX_INLINE_BYTES));

    expect(strlen($attachment->content))->toBe(AttachmentData::MAX_INLINE_BYTES);
});
