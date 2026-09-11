<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Konventix\Admission\Models\Admission;

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
