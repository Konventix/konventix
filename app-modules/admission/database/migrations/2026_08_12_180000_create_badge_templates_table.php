<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-event badge templates (D-ADM-05). Admission owns the layout; the
     * assets it references live in Platform's media library and are stored
     * here as ULIDs only (ADR-0035).
     *
     * `role` is nullable and acts as the match key: a template with a role
     * applies to admissions of that role, and the one with a null role is the
     * event's fallback. That is why the unique index spans event + role — one
     * template per role per event, and exactly one fallback.
     */
    public function up(): void
    {
        Schema::create('badge_templates', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name');

            // Null = the event's fallback template, used by any admission
            // whose role has no template of its own.
            $table->string('role')->nullable();

            // Typed layout behind a Data cast (ADR-0009) — never loose
            // columns, so adding a layout field does not mean a migration.
            $table->json('layout');

            $table->timestamps();

            // Postgres treats NULLs as distinct in a unique index, so this
            // alone would permit several fallbacks. The partial index below
            // closes that.
            $table->unique(['brand_id', 'event_id', 'role']);
        });

        // One fallback per event, enforced where the plain unique index
        // cannot reach.
        DB::statement(
            'CREATE UNIQUE INDEX badge_templates_fallback_unique
             ON badge_templates (brand_id, event_id)
             WHERE role IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_templates');
    }
};
