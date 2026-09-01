<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Actions\RefuseSubjectRequest;
use Konventix\Compliance\Console\WatchSubjectRequestDeadlinesCommand;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Listeners\NotifySubjectOfRequestOutcome;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Recipient;
use Konventix\Platform\Models\Operator;

/**
 * What the subject and the operator are told (Art. 12(3), and Round 6
 * D-BATCH's deadline scheduler).
 */
it('tells the subject their export is ready, without attaching it', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');

    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->id]);
    Recipient::query()->create([
        'id' => $user->id,
        'email' => 'alex@example.test',
        'display_name' => 'Alex',
        'locale' => 'de',
    ]);

    app(OpenSubjectRequest::class)(SubjectRef::forUser($user->id), DataSubjectRequestType::Export, null, $brand->getKey());

    $record = MessageRecord::withoutBrandScope()
        ->where('template_key', NotifySubjectOfRequestOutcome::EXPORT_READY)
        ->firstOrFail();

    expect($record->recipient_email)->toBe('alex@example.test')
        ->and($record->brand_id)->toBe($brand->id)
        // The archive is downloaded after signing in; mailing it would put a
        // person's whole record in an inbox.
        ->and($record->rendered_body)->not->toContain('.json');
});

it('acknowledges a deletion while the address still exists', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->id]);
    Recipient::query()->create([
        'id' => $user->id,
        'email' => 'alex@example.test',
        'display_name' => 'Alex',
        'locale' => 'de',
    ]);

    app(OpenSubjectRequest::class)(SubjectRef::forUser($user->id), DataSubjectRequestType::Deletion, DeletionMode::Anonymize);

    // The mail itself is the proof of ordering: it left for the real address
    // before the fan-out scrubbed every trace of it. `MAIL_MAILER=array` in
    // phpunit.xml keeps sent messages inspectable.
    $sent = collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->map(fn ($message): string => $message->getOriginalMessage()->getTo()[0]->getAddress());

    expect($sent)->toContain('alex@example.test');

    MessageRecord::withoutBrandScope()
        ->where('template_key', NotifySubjectOfRequestOutcome::DELETION_ACKNOWLEDGED)
        ->firstOrFail();

    // And afterwards nothing points at the person any more — including the
    // delivery-log row of the acknowledgment itself.
    expect(Recipient::query()->whereKey($user->id)->value('email'))->toContain('@invalid')
        ->and(MessageRecord::withoutBrandScope()
            ->where('recipient_id', $user->id)
            ->where('recipient_email', 'alex@example.test')
            ->exists())->toBeFalse();
});

it('tells the subject a refusal and its legal ground', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    Recipient::query()->create([
        'id' => $user->id,
        'email' => 'alex@example.test',
        'display_name' => 'Alex',
        'locale' => 'de',
    ]);

    $request = DataSubjectRequest::factory()->create([
        'subject_id' => $user->id,
        'type' => DataSubjectRequestType::Rectification,
    ]);

    (new RefuseSubjectRequest)($request, 'art_12_5_excessive');

    $record = MessageRecord::withoutBrandScope()
        ->where('template_key', NotifySubjectOfRequestOutcome::REQUEST_REFUSED)
        ->firstOrFail();

    expect($record->rendered_body)->toContain('art_12_5_excessive');
});

it('refuses an email-only request and mails the bare address (ADR-0050 §9 ruling 3)', function (): void {
    $this->withBrand();

    $request = DataSubjectRequest::factory()->forEmail('someone@example.de')->create([
        'type' => DataSubjectRequestType::Rectification,
    ]);

    // No account behind this subject, so the console can still refuse the
    // request — this must not TypeError on a non-nullable subjectId.
    (new RefuseSubjectRequest)($request, 'art_12_5_excessive');

    $record = MessageRecord::withoutBrandScope()
        ->where('template_key', NotifySubjectOfRequestOutcome::REQUEST_REFUSED)
        ->firstOrFail();

    expect($record->recipient_email)->toBe('someone@example.de')
        ->and($record->recipient_id)->toBeNull()
        ->and($record->rendered_body)->toContain('art_12_5_excessive');
});

it('escalates an approaching deadline once, then again when it lapses', function (): void {
    $brand = $this->withBrand();

    $operator = Operator::factory()->create(['contact_email' => 'legal@operator.test']);
    $brand->forceFill(['operator_id' => $operator->id])->save();

    $request = DataSubjectRequest::factory()->create([
        'filed_on_brand_id' => $brand->getKey(),
        'state' => DataSubjectRequestState::Received,
        'deadline_at' => now()->addDays(3),
    ]);

    $this->artisan(WatchSubjectRequestDeadlinesCommand::class)->assertSuccessful();

    expect($request->refresh()->escalation_level)->toBe(1)
        ->and(MessageRecord::withoutBrandScope()
            ->where('template_key', WatchSubjectRequestDeadlinesCommand::TEMPLATE_KEY)
            ->count())->toBe(1);

    // A second run on the same day changes nothing: a daily nag is a mail an
    // operator learns to filter.
    $this->artisan(WatchSubjectRequestDeadlinesCommand::class)->assertSuccessful();

    expect(MessageRecord::withoutBrandScope()
        ->where('template_key', WatchSubjectRequestDeadlinesCommand::TEMPLATE_KEY)
        ->count())->toBe(1);

    $request->forceFill(['deadline_at' => now()->subDay()])->save();

    $this->artisan(WatchSubjectRequestDeadlinesCommand::class)->assertSuccessful();

    expect($request->refresh()->escalation_level)->toBe(2)
        ->and(MessageRecord::withoutBrandScope()
            ->where('template_key', WatchSubjectRequestDeadlinesCommand::TEMPLATE_KEY)
            ->count())->toBe(2);
});

it('leaves a closed request alone and one whose deadline is far off', function (): void {
    $brand = $this->withBrand();

    $operator = Operator::factory()->create(['contact_email' => 'legal@operator.test']);
    $brand->forceFill(['operator_id' => $operator->id])->save();

    DataSubjectRequest::factory()->create([
        'state' => DataSubjectRequestState::Fulfilled,
        'deadline_at' => now()->subMonth(),
    ]);
    DataSubjectRequest::factory()->create([
        'state' => DataSubjectRequestState::Received,
        'deadline_at' => now()->addDays(25),
    ]);

    $this->artisan(WatchSubjectRequestDeadlinesCommand::class)->assertSuccessful();

    expect(MessageRecord::withoutBrandScope()
        ->where('template_key', WatchSubjectRequestDeadlinesCommand::TEMPLATE_KEY)
        ->count())->toBe(0);
});
