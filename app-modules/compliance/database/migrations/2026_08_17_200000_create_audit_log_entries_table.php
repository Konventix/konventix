<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail (SPEC "Owns"): an append-only record of privileged actions
 * — who did what, to whom, in which brand, when.
 *
 * Deliberately **not** brand-scoped through `BelongsToBrand`. The trail spans
 * brands by design (SPEC: "across brands"), and half of what it records
 * happens on the operator host where no brand is resolved at all — an
 * operator downloading a subject export, a role being edited. `brand_id` is
 * therefore nullable data on the row, not a scoping mechanism, and the trail
 * is readable only through the operator console behind
 * `compliance.view-audit-log`.
 *
 * Nothing updates or deletes these rows. Retention (36 months, D-CPL-01) is
 * the retention machinery's job, not a cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Nullable: an action taken by a scheduled job or a queue worker
            // has no actor, and recording a fake one would be worse than
            // recording none.
            $table->ulid('actor_user_id')->nullable()->index();

            // Dot-namespaced by owning module — `compliance.policy.published`,
            // `registration.registrations.exported`. The prefix is what makes
            // the trail filterable without a table of action types.
            $table->string('action')->index();

            // Who or what the action was about. A ULID with no FK: it may
            // point into any module, or at a subject with no account at all
            // (same shape as consents.subject_id).
            $table->ulid('subject_id')->nullable()->index();

            $table->ulid('brand_id')->nullable()->index();

            // Whatever the action needs to be understood a year later, as
            // scalars. Never Eloquent models, never personal data beyond what
            // the action itself is about.
            $table->json('context')->nullable();

            // Recorded, not inferred: `created_at` alone would tie the trail's
            // meaning to row-insertion time, and a queued job may record long
            // after the fact.
            $table->timestamp('occurred_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
    }
};
