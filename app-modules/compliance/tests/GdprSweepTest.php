<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequestResponse;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\PushSubscription;
use Konventix\Messaging\Models\Recipient;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Models\Registration;

/**
 * The slice-14 sweep: every module that holds personal data now answers the
 * fan-out, and a deletion leaves tombstones rather than dangling references
 * (Round 6 D-BATCH).
 */
it('registers a handler for every module that holds personal data', function (): void {
    $modules = app(GdprHandlerRegistry::class)->moduleNames();

    expect($modules)->toContain(
        'identity',
        'registration',
        'admission',
        'content',
        'messaging',
        'compliance',
        'staffing',
        'competition',
        'community',
    );
});

it('assembles a real export from every registered module', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');

    $user = User::factory()->create([
        'name' => 'Alex Muster',
        'email' => 'alex@example.test',
        'username' => 'alex-muster',
        'tagline' => 'Brings the cables',
    ]);
    BrandMembership::factory()->create(['user_id' => $user->id]);
    Registration::factory()->create(['registrant_user_id' => $user->id]);
    Admission::factory()->create(['holder_user_id' => $user->id]);
    Recipient::query()->create([
        'id' => $user->id,
        'email' => $user->email,
        'display_name' => $user->name,
        'locale' => 'de',
    ]);
    // Community's rows are projections of Identity's events (ADR-0004); the
    // sweep arranges them directly, the way it does Content's Author and
    // Messaging's Recipient above.
    CommunityProfile::query()->create([
        'id' => $user->id,
        'username' => 'alex-muster',
        'display_name' => 'Alex Muster',
        'tagline' => 'Brings the cables',
        'visibility' => 'internal',
    ]);
    CommunityMember::query()->create(['user_id' => $user->id, 'member_since' => now()]);

    $request = app(OpenSubjectRequest::class)(SubjectRef::forUser($user->id), DataSubjectRequestType::Export);
    $request->refresh();

    $archive = json_decode(Storage::disk('exports')->get((string) $request->archive_path), true);

    expect($archive['modules']['identity']['account']['email'])->toBe('alex@example.test')
        ->and($archive['modules']['identity']['account']['username'])->toBe('alex-muster')
        ->and($archive['modules']['identity']['account']['tagline'])->toBe('Brings the cables')
        ->and($archive['modules']['registration']['registrations'])->toHaveCount(1)
        ->and($archive['modules']['admission']['admissions'])->toHaveCount(1)
        ->and($archive['modules']['messaging']['recipient']['display_name'])->toBe('Alex Muster')
        ->and($archive['modules']['community']['profile']['username'])->toBe('alex-muster')
        ->and($archive['modules']['community']['memberships'])->toHaveCount(1)
        ->and($archive['modules'])->toHaveKeys(['compliance', 'content', 'staffing', 'competition']);

    expect(DataSubjectRequestResponse::query()
        ->where('data_subject_request_id', $request->id)
        ->where('state', '!=', ModuleResponseState::Completed)
        ->count())->toBe(0);
});

it('anonymizes across modules while every reference still resolves', function (): void {
    $brand = $this->withBrand();

    $user = User::factory()->create([
        'name' => 'Alex Muster',
        'username' => 'alex-muster',
        'tagline' => 'Brings the cables',
        'status_emoji' => '🔌',
        'social_links' => ['mastodon' => 'https://example.test/@alex'],
    ]);
    BrandMembership::factory()->create(['user_id' => $user->id]);
    CommunityProfile::query()->create([
        'id' => $user->id,
        'username' => 'alex-muster',
        'display_name' => 'Alex Muster',
        'visibility' => 'internal',
    ]);
    CommunityMember::query()->create(['user_id' => $user->id, 'member_since' => now()]);
    $wallComment = CommunityComment::factory()->create(['author_user_id' => $user->id]);

    $registration = Registration::factory()->create([
        'registrant_user_id' => $user->id,
        'billing_email' => 'alex@example.test',
    ]);
    $admission = Admission::factory()->create(['holder_user_id' => $user->id]);
    $claimLink = AdmissionClaimLink::factory()->create([
        'admission_id' => $admission->id,
        'intended_email' => 'alex@example.test',
    ]);
    Author::query()->create(['id' => $user->id, 'display_name' => 'Alex Muster']);
    $comment = ContentComment::factory()->create([
        'content_entry_id' => ContentEntry::factory()->create()->id,
        'author_user_id' => $user->id,
    ]);
    Recipient::query()->create([
        'id' => $user->id,
        'email' => 'alex@example.test',
        'display_name' => 'Alex Muster',
        'locale' => 'de',
    ]);
    PushSubscription::query()->create([
        'recipient_id' => $user->id,
        'endpoint' => 'https://push.test/abc',
        'p256dh' => 'key',
        'auth' => 'auth',
        'content_encoding' => 'aes128gcm',
    ]);

    app(OpenSubjectRequest::class)(SubjectRef::forUser($user->id), DataSubjectRequestType::Deletion, DeletionMode::Anonymize);

    $user->refresh();

    expect($user->state)->toBe(AccountState::Anonymized)
        ->and($user->name)->toBe('Deleted account')
        ->and($user->email)->toContain('@invalid')
        // The profile shapes go with the account (ADR-0051 §9): the freed
        // username slot must not resolve to someone else's page.
        ->and($user->username)->toBe('deleted-'.strtolower((string) $user->getKey()))
        ->and($user->tagline)->toBeNull()
        ->and($user->status_emoji)->toBeNull()
        ->and($user->social_links)->toBeNull()
        // The tombstone: every foreign reference still points at a row.
        ->and($registration->refresh()->registrant_user_id)->toBe($user->id)
        ->and($admission->refresh()->holder_user_id)->toBe($user->id)
        ->and($comment->refresh()->author_user_id)->toBe($user->id);

    expect($registration->billing_email)->toBeNull()
        ->and($claimLink->refresh()->intended_email)->toBeNull()
        ->and(Author::query()->whereKey($user->id)->value('display_name'))->toBe('Deleted account')
        ->and(Recipient::query()->whereKey($user->id)->value('email'))->toContain('@invalid')
        ->and(PushSubscription::query()->where('recipient_id', $user->id)->exists())->toBeFalse();

    // The comment body survives — a thread other people replied to is not one
    // person's to rewrite (see ContentGdprHandler).
    expect($comment->body)->not->toBeEmpty();

    // Community holds read models and the person's own words on other
    // people's walls; nothing outside the module references either, so they
    // are removed rather than tombstoned (see CommunityGdprHandler).
    expect(CommunityProfile::query()->find($user->id))->toBeNull()
        ->and(CommunityMember::withoutBrandScope()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(CommunityComment::withoutBrandScope()->whereKey($wallComment->getKey())->exists())->toBeFalse();
});

it('anonymizes an account and removes every brand membership, even one belonging to another brand (ADR-0050)', function (): void {
    $brandA = $this->withBrand();
    $brandB = Brand::factory()->create();

    $user = User::factory()->create();

    // One membership per brand, each stamped by the context it was created
    // in (ADR-0010). Before ADR-0050 a deletion request arrived per brand and
    // this membership pair is exactly what made the account survive; now the
    // request is about the person, not the person-at-a-brand, so both go.
    BrandMembership::factory()->create(['user_id' => $user->id]);
    $this->withBrand($brandB);
    BrandMembership::factory()->create(['user_id' => $user->id]);
    $this->withBrand($brandA);

    app(OpenSubjectRequest::class)(SubjectRef::forUser($user->id), DataSubjectRequestType::Deletion, DeletionMode::Anonymize);

    expect($user->refresh()->state)->toBe(AccountState::Anonymized)
        ->and(BrandMembership::withoutBrandScope()->where('user_id', $user->id)->count())->toBe(0);
});

it('leaves the consent record standing after a deletion', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->id]);

    $version = PolicyVersion::factory()->for(Policy::factory())->published()->create();
    Consent::factory()->create(['subject_id' => $user->id, 'policy_version_id' => $version->id]);

    app(OpenSubjectRequest::class)(SubjectRef::forUser($user->id), DataSubjectRequestType::Deletion, DeletionMode::Anonymize);

    // Destroying the proof that consent was given, in order to honour a
    // deletion request, would destroy the proof the request was honoured.
    expect(Consent::query()->where('subject_id', $user->id)->count())->toBe(1);
});
