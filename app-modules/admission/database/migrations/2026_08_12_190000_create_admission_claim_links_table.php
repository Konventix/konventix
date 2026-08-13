<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Claim links for holder-less admissions (ADR-0032, D-COM-09).
     *
     * The ADR settles *where* the placeholder lives but explicitly not how it
     * gets claimed — "nothing defines what a claim link even looks like (a
     * signed URL, a short code, an email-bound token)". This follows the
     * pattern the codebase already uses for waitlist offers: a temporary
     * signed route, whose signature expiry IS the row's `expires_at`, so the
     * URL and the domain object cannot disagree about when the offer closes.
     *
     * A row per link rather than signing the admission id directly, because a
     * link needs its own lifecycle: revocation before use, an audit of who
     * claimed which slot and when, and re-issuing a fresh link without
     * reviving an old one someone forwarded.
     */
    public function up(): void
    {
        Schema::create('admission_claim_links', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014).
            $table->foreignUlid('admission_id')->constrained()->cascadeOnDelete();

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('claimed_by_user_id')->nullable();

            // Optional: an organizer may bind a link to one address so a
            // forwarded link cannot be claimed by whoever received it.
            $table->string('intended_email')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // At most one live link per admission — see the partial index
            // below, which is what actually enforces it.
            $table->index(['admission_id', 'claimed_at']);
        });

        // One unclaimed, unrevoked link per admission. Without this, an
        // organizer clicking "send link" twice creates two live links to the
        // same slot and the second person to arrive is turned away by a race
        // rather than by a rule.
        DB::statement(
            'CREATE UNIQUE INDEX admission_claim_links_one_live
             ON admission_claim_links (admission_id)
             WHERE claimed_at IS NULL AND revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_claim_links');
    }
};
