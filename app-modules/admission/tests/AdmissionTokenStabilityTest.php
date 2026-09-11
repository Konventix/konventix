<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceDeltaEntry;

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
