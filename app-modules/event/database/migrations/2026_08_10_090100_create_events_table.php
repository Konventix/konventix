<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Venue is optional (multi-venue/online events stay an open
            // question, SPEC.md). Within-module foreign key is fine — venues
            // is owned by this same module (ADR-0014).
            $table->foreignUlid('venue_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // Wall-clock local time, no timezone offset baked in — ADR-0027.
            // `timezone` is the IANA zone the wall-clock values are promised
            // in. Conversion to real instants happens only through the
            // model's startsAtInstant()/endsAtInstant() accessors.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone');

            // Lifecycle state — draft/announced/published/running/concluded/
            // archived, plus cancelled as a first-class transition rather
            // than a soft delete (SPEC.md).
            $table->string('state')->default('draft');

            // Whether an unpublished event is reachable via direct link
            // (SPEC.md "Event visibility").
            $table->string('visibility')->default('public');

            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Set by CancelEvent; a business reason code, not a free-text
            // field (SPEC.md payload for EventCancelled).
            $table->string('reason_code')->nullable();

            $table->timestamps();

            // Slugs are unique per brand, not globally.
            $table->unique(['brand_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
