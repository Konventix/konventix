<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Registration and Event are different modules — plain ULIDs,
            // no FK constraint (ADR-0014).
            $table->ulid('registration_id');
            $table->ulid('event_id');

            // Nullable: an admission may be issued unassigned for group and
            // B2B bookings and claimed or named later (SPEC.md "Owns" —
            // "holder assignment"). Identity is a different module — plain
            // ULID, no FK.
            $table->ulid('holder_user_id')->nullable();

            $table->string('role');

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            $table->string('state')->default('issued');

            $table->timestamps();

            // The idempotency guard IssueAdmissionsForRegistration relies on
            // — "has this registration already produced admissions at all?"
            $table->index('registration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
