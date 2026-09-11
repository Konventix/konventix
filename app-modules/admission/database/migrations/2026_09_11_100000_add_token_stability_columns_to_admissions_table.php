<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stable ticket code (design doc §5, TKT-F-018 ported as is). Both
     * columns are nullable and stay null for every admission that exists
     * before this migration runs — `VerifyAdmissionToken::isSuperseded()`
     * (Task 7) treats a null `token_nonce_hash` as "accept anything",
     * which is what keeps every token already in circulation working
     * across the deploy with no backfill (`IssueAdmissionToken`'s own
     * docblock, Task 9, says why one is not run).
     *
     * `token_nonce_hash` is HMAC-SHA256 of the token's nonce, keyed with
     * `config('admission.token_pepper')`; the plaintext nonce itself is
     * never persisted anywhere. `token_issued_at` is both a support-
     * question timestamp and, since the nonce is deterministic rather than
     * random, the one piece of state `IssueAdmissionToken::current()`
     * needs to regenerate the exact same nonce on a later call without
     * ever having stored it. Deliberately left at the schema's default
     * second precision — see this file's own section header for why a
     * finer one would not survive a database round trip anyway.
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->string('token_nonce_hash', 64)->nullable()->after('state');
            $table->timestamp('token_issued_at')->nullable()->after('token_nonce_hash');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropColumn(['token_nonce_hash', 'token_issued_at']);
        });
    }
};
