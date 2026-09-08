<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-wide legal texts (GitLab #76).
 *
 * The operator is one legal entity behind all of its brands (ADR-0016), so
 * its AGB and Widerrufsbelehrung are written once and every brand inherits
 * them. Until now `policies.brand_id` was NOT NULL, which meant a new brand
 * had to re-enter every legal text before it was lawful to sell anything —
 * the failure mode `retention_rules` already avoids with the same nullable
 * column and the same hand-written constraint.
 *
 * `policy_versions.brand_id` follows: a policy nobody may write a version
 * for is not a policy. The row's scope is the policy's scope.
 *
 * `UNIQUE NULLS NOT DISTINCT` (Postgres 15+) is written by hand for the same
 * reason it is in `retention_rules`: a plain unique index treats every NULL
 * as distinct, so the operator-wide rows — the ones every brand falls back
 * to — would not be constrained at all, and two "terms" texts would both
 * claim to be the operator's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            // Named explicitly: Laravel derives `policies_brand_id_slug_unique`
            // from the original `unique(['brand_id', 'slug'])`.
            $table->dropUnique('policies_brand_id_slug_unique');
            $table->ulid('brand_id')->nullable()->change();
        });

        DB::statement(
            'ALTER TABLE policies ADD CONSTRAINT policies_brand_id_slug_unique
             UNIQUE NULLS NOT DISTINCT (brand_id, slug)'
        );

        Schema::table('policy_versions', function (Blueprint $table): void {
            $table->ulid('brand_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // An operator-wide row cannot survive a column that forbids NULL, and
        // guessing a brand for it would invent a legal text's owner. Drop
        // them; the texts themselves live in `policy_versions`, which cascade.
        DB::table('policies')->whereNull('brand_id')->delete();

        DB::statement('ALTER TABLE policies DROP CONSTRAINT policies_brand_id_slug_unique');

        Schema::table('policies', function (Blueprint $table): void {
            $table->ulid('brand_id')->nullable(false)->change();
            $table->unique(['brand_id', 'slug']);
        });

        Schema::table('policy_versions', function (Blueprint $table): void {
            $table->ulid('brand_id')->nullable(false)->change();
        });
    }
};
