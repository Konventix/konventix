<?php

declare(strict_types=1);

use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Mail\TemplatedMail;

/**
 * Spec §6.3: `attachments()` builds an in-memory `Attachment::fromData()`
 * for the inline form and keeps `fromStorageDisk()` for the disk form.
 * `InvoiceMailTest.php` already exercises the disk form end to end through
 * `SendInvoiceMail` and must keep passing unchanged; this file covers
 * `TemplatedMail` directly, for both forms.
 */
function templatedMail(array $attachments): TemplatedMail
{
    return new TemplatedMail(
        messageSubject: 'Subject',
        htmlBody: '<p>Body</p>',
        textBody: 'Body',
        fromAddress: 'noreply@example.test',
        fromName: 'Konventix',
        replyToAddress: null,
        fileAttachments: $attachments,
    );
}

it('resolves an inline attachment through the data strategy, carrying the given name and mime type', function (): void {
    $mail = templatedMail([
        new AttachmentData(as: 'event.ics', content: 'BEGIN:VCALENDAR', mime: 'text/calendar'),
    ]);

    $attachment = $mail->attachments()[0];

    // attachWith() is the same public entry point Attachment::attachTo()
    // uses internally when a real mail is sent — invoking it directly here
    // resolves the closure without needing a configured mailer or a real
    // storage disk.
    $resolved = $attachment->attachWith(
        pathStrategy: fn (string $path): string => $path,
        dataStrategy: fn (Closure $data): string => $data(),
    );

    expect($resolved)->toBe('BEGIN:VCALENDAR')
        ->and($attachment->as)->toBe('event.ics')
        ->and($attachment->mime)->toBe('text/calendar');
});

it('still builds a disk attachment, unchanged, for the disk form', function (): void {
    $mail = templatedMail([
        new AttachmentData(as: 'invoice.pdf', disk: 'invoices', path: 'invoices/INV-1.pdf', mime: 'application/pdf'),
    ]);

    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toBe('invoice.pdf')
        ->and($attachments[0]->mime)->toBe('application/pdf');
});
