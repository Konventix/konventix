<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two Community read models, both projected from Identity's events
     * (ADR-0004) — Community may not read Identity's tables.
     *
     * `community_profiles` is the operator-wide read model: username and
     * display name, keyed by the user's own ULID assigned from the event
     * (Author-model precedent, `app-modules/content`). Not brand-scoped —
     * accounts span the operator (ADR-0016) — so this table exists whether
     * or not any brand has Community switched on.
     *
     * `community_members` is the per-brand membership: a brand-owned row
     * with the participant's avatar/banner and "member since" timestamp.
     * `user_id` is Identity's account — a plain ULID column, no FK
     * (ADR-0014), same as `brand_id` names Platform's `brands` table via an
     * explicit constraint. Rows here only ever exist for brands that have
     * activated the module (SPEC.md "Activation").
     */
    public function up(): void
    {
        Schema::create('community_profiles', function (Blueprint $table): void {
            // PK is the user's own ULID, assigned from the event (Author-model
            // precedent). Not brand-scoped: accounts span the operator (ADR-0016).
            $table->ulid('id')->primary();
            $table->string('username', 64);
            $table->string('display_name');
            $table->string('tagline', 140)->nullable();
            $table->string('status_emoji', 32)->nullable();
            $table->json('social_links')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX community_profiles_username_lower ON community_profiles (lower(username))');

        Schema::create('community_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // Identity's account — plain ULID, no FK (ADR-0014).
            $table->ulid('user_id')->index();
            $table->string('avatar_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->timestamp('member_since');
            $table->timestamps();

            $table->unique(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        // The username index dies with its table.
        Schema::dropIfExists('community_members');
        Schema::dropIfExists('community_profiles');
    }
};
