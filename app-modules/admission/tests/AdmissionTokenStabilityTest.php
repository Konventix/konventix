<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RecordScan;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;

/**
 * §5: a stable ticket code. This file grows one `it()` per task in this
 * plan section (Tasks 5-10) rather than one file per task, because every
 * later case exercises the same `IssueAdmissionToken`/`VerifyAdmissionToken`
 * /`RecordScan` triangle Task 6 and 7 stand up.
 */
it('gives admissions nullable token-stability columns and entrance_delta_entries a payload column, with a pepper needing no new env var', function (): void {
    expect(Schema::hasColumns('admissions', ['token_nonce_hash', 'token_issued_at']))->toBeTrue()
        ->and(Schema::hasColumn('entrance_delta_entries', 'payload'))->toBeTrue();

    $this->withBrand();
    $admission = Admission::factory()->create();

    expect($admission->token_nonce_hash)->toBeNull()
        ->and($admission->token_issued_at)->toBeNull();

    expect(config('admission.token_pepper'))->toBeString()
        ->and(config('admission.token_pepper'))->not->toBe('');
});

it('returns the same token body from two current() calls, and a different one after rotate()', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create();
    $issuer = app(IssueAdmissionToken::class);

    $first = $issuer->current($admission);
    $second = $issuer->current($admission->fresh());

    expect($second)->toBe($first);

    $this->travel(1)->second();
    $rotated = $issuer->rotate($admission->fresh());

    expect($rotated)->not->toBe($first);
});

it('writes a token_superseded delta entry carrying the new hash on rotate()', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create();
    app(IssueAdmissionToken::class)->rotate($admission);

    $admission->refresh();

    $entry = EntranceDeltaEntry::withoutBrandScope()
        ->where('admission_id', $admission->id)
        ->where('kind', EntranceDeltaEntry::KIND_TOKEN_SUPERSEDED)
        ->firstOrFail();

    expect($entry->payload)->toBe(['hash' => $admission->token_nonce_hash])
        ->and($admission->token_nonce_hash)->not->toBeNull();
});

it('refuses a token minted before rotation with NonceSuperseded, through RecordScan, and accepts one for an admission with no stored hash', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);
    $key = EntranceSigningKey::withoutBrandScope()->where('brand_id', $brand->id)->firstOrFail();

    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);
    $issuer = app(IssueAdmissionToken::class);

    $stale = $issuer->current($admission);

    $this->travel(1)->second();
    $issuer->rotate($admission->fresh());

    $rejected = app(RecordScan::class)->handle(
        token: $stale,
        brandId: $brand->id,
        eventId: $eventId,
        clientScanId: 'stale-1',
    );

    expect($rejected->result)->toBe(CheckInResult::Rejected)
        ->and($rejected->reason_code)->toBe(CheckInRejectionReason::NonceSuperseded->value);

    // An admission with no stored hash — nothing has rotated for it since
    // before this enforcement shipped — accepts anything: this is what
    // keeps every token already in circulation working across the deploy.
    $preDeploy = Admission::factory()->create(['event_id' => $eventId]);
    $preDeployToken = (new AdmissionTokenCodec)->encode(
        new AdmissionTokenPayload(
            admissionId: $preDeploy->id,
            eventId: $eventId,
            nonce: (string) Str::ulid(),
            issuedAt: now()->subDays(10)->getTimestamp(),
            expiresAt: now()->addDays(50)->getTimestamp(),
        ),
        $key->kid,
        $key->private_key,
    );

    $accepted = app(RecordScan::class)->handle(
        token: $preDeployToken,
        brandId: $brand->id,
        eventId: $eventId,
        clientScanId: 'pre-deploy-1',
    );

    expect($accepted->result)->toBe(CheckInResult::Accepted);
});
