<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoints and the scanner devices enrolled at them (ADR-0033).
     *
     * A checkpoint is **policy-free by design**: it carries no re-entry knob
     * of its own, because D-ADM-03 puts that on the event and a checkpoint
     * merely enforces whatever the event says. Resisting a `reentry_policy`
     * column here is the whole point.
     *
     * Devices replace LanCore's single shared bearer token for the entire
     * entrance API, which had no per-device identity and no revocation story:
     * one leaked token meant rotating every scanner at the venue.
     */
    public function up(): void
    {
        Schema::create('checkpoints', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name');

            $table->timestamps();

            $table->index(['brand_id', 'event_id']);
        });

        Schema::create('entrance_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module, so a real FK is allowed (ADR-0014).
            $table->foreignUlid('checkpoint_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // Only the hash is stored; the plaintext is shown once at
            // enrolment and never recoverable. Unique so a lookup by hash is
            // a single indexed read rather than a scan over every device.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('last_seen_at')->nullable();

            // Revoking is a timestamp, not a delete: a lost scanner's scan
            // history has to stay auditable after it is locked out.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrance_devices');
        Schema::dropIfExists('checkpoints');
    }
};
