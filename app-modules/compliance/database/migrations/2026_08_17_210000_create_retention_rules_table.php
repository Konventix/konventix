<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retention rules and the clocks they run against (SPEC "Owns").
 *
 * Compliance publishes the rule; the module that holds the data deletes its
 * own rows when the period elapses. Nothing here deletes anything — that
 * separation is the whole design, and it is why a rule names a *data
 * category* rather than a table.
 *
 * `brand_id` is nullable: an operator-wide rule (the audit trail, the
 * delivery log) applies everywhere, while a brand may publish its own for
 * data it collects. The two D-CPL-01 numbers ship as operator-wide defaults
 * below, because a platform that has to be configured before it is lawful is
 * not lawful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Null = operator-wide. Not `BelongsToBrand`, deliberately: the
            // rules that matter most are the ones no single brand owns, and
            // they are administered from the operator console where no brand
            // is resolved (ADR-0029).
            $table->ulid('brand_id')->nullable()->index();

            // Dot-namespaced by owning module — `messaging.delivery-log`,
            // `compliance.audit-trail`. The owning module recognises its own
            // categories and ignores everything else; nobody maintains a
            // central enum of them.
            $table->string('data_category');

            $table->unsignedSmallInteger('retention_months');

            // `record_age` counts from the row's own age; `event_concluded`
            // starts when an event ends, which is what most participant data
            // hangs off.
            $table->string('clock_start')->default('record_age');

            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

        });

        // One rule per category per scope — two rules for the same data would
        // mean two answers to "how long may we keep this".
        //
        // `NULLS NOT DISTINCT` (Postgres 15+) is the whole point of writing
        // this by hand: a plain unique index treats every NULL as distinct, so
        // the operator-wide rows — the ones that matter most, and the only
        // ones seeded — would not be constrained at all.
        DB::statement(
            'ALTER TABLE retention_rules ADD CONSTRAINT retention_rules_scope_category_unique
             UNIQUE NULLS NOT DISTINCT (brand_id, data_category)'
        );

        Schema::create('retention_clocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('brand_id')->index();

            // Cross-module reference to Event, by ULID with no FK (ADR-0014).
            // Compliance records that an event concluded and never reads
            // Event's tables to find out.
            $table->ulid('event_id');

            $table->timestamp('concluded_at');
            $table->timestamps();

            $table->unique('event_id');
        });

        // The two numbers settled in Round 7 (D-CPL-01), seeded so the
        // platform is compliant out of the box rather than after someone
        // remembers to configure it.
        $now = now();

        foreach ([
            ['compliance.audit-trail', 36, 'Audit trail entries (D-CPL-01).'],
            ['messaging.delivery-log', 12, 'Message delivery log (D-CPL-01).'],
        ] as [$category, $months, $description]) {
            DB::table('retention_rules')->insert([
                'id' => (string) Str::ulid(),
                'brand_id' => null,
                'data_category' => $category,
                'retention_months' => $months,
                'clock_start' => 'record_age',
                'description' => $description,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_clocks');
        Schema::dropIfExists('retention_rules');
    }
};
