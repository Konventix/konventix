<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0052: `community_profiles.visibility` mirrors Identity's
 * `profile_visibility` (plain string in the read model, same as every other
 * field on this table). `community_event_participations` is the third read
 * model, projected from Registration's contract events — Community may not
 * read Registration's tables (ADR-0004).
 *
 * The PK is the REGISTRATION id carried in the event, not a generated ULID
 * (no `HasUlids` on the model — same non-generated-PK shape as
 * `community_profiles`). Brand-owned (`brand_id` via `BelongsToBrand`,
 * forceFill-only, same as `community_members`). `user_id`/`event_id` are
 * plain ULID columns naming Identity's account and Registration's event,
 * no FK (ADR-0014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->string('visibility')->default('internal')->after('social_links');
        });

        Schema::create('community_event_participations', function (Blueprint $table): void {
            // PK = the registration id from the event, not generated.
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // Identity's account and Registration's event — plain ULIDs,
            // no FK (ADR-0014).
            $table->ulid('user_id')->index();
            $table->ulid('event_id')->index();
            $table->boolean('confirmed')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_participations');

        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
