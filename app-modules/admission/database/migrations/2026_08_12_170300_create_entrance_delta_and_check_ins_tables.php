<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The PII-free delta feed and the check-in ledger (ADR-0033).
     *
     * The delta carries **opaque admission IDs only** — no names, no hashes
     * standing in for names. That is the ADR's central guarantee: a stolen
     * scanner leaks nothing about attendees. Any column added here that could
     * identify a person breaks that promise, so there are none.
     *
     * The cursor is a monotonic sequence rather than a timestamp: venue
     * hardware has unreliable clocks, and a device asking "what changed since
     * time T" against a skewed clock silently misses rows. Asking "since
     * sequence N" cannot.
     */
    public function up(): void
    {
        Schema::create('entrance_delta_entries', function (Blueprint $table): void {
            // The cursor. Deliberately an auto-incrementing integer and not a
            // ULID: it is an ordering the client walks, not an identity.
            $table->bigIncrements('sequence');

            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->ulid('event_id');
            $table->ulid('admission_id');

            // 'revoked' | 'checked_in' — the two negative facts a signature
            // cannot carry on its own.
            $table->string('kind', 16);

            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'sequence']);
        });

        Schema::create('check_ins', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FK allowed (ADR-0014). Nullable because the
            // commonest rejection is a token whose signature does not verify,
            // which never resolves to an admission at all — and that scan
            // still belongs in the audit trail.
            $table->foreignUlid('admission_id')->nullable()->constrained()->cascadeOnDelete();

            // Denormalized from the admission on purpose: ADR-0037 needs
            // `registration_id` on the emitted events so Commerce can block a
            // refund on a checked-in admission through its own read model,
            // without ever reading Admission's tables. Null exactly when
            // admission_id is.
            $table->ulid('registration_id')->nullable();

            $table->foreignUlid('checkpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('entrance_devices')->nullOnDelete();

            // Which event day this scan counted against (D-ADM-04). Null when
            // the scan fell outside every day — itself a rejection reason.
            $table->ulid('event_day_id')->nullable();

            $table->string('direction', 8)->default('in');
            $table->string('result', 16);
            $table->string('reason_code')->nullable();

            // When the device scanned, versus when the server heard about it.
            // They differ by however long the uplink was down.
            $table->timestamp('scanned_at');
            $table->timestamp('synced_at')->nullable();

            // The device's own id for the scan. Unique per brand so a device
            // replaying its buffer after reconnecting writes each scan once —
            // reconnection replay is the normal case offline, not an edge one.
            $table->string('client_scan_id', 64);

            $table->timestamps();

            $table->unique(['brand_id', 'client_scan_id']);
            $table->index(['admission_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('entrance_delta_entries');
    }
};
