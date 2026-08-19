<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPEC.md: "A linked identity is a row (`provider`, `provider_user_id`,
     * linked-at) referencing the user — never a column on User." LanCore hung
     * Steam columns off its user table and that is precisely how Steam stopped
     * being one driver among several.
     *
     * Not brand-scoped: an account spans the operator (ADR-0016), so the ways
     * into it do too. A person who linked Steam at one brand is linked at all
     * of them, which is the same promise the account itself makes.
     *
     * `user_id` carries a real foreign key — this is Identity's own table
     * referencing Identity's own users, so ADR-0014's no-constraint rule (which
     * governs *cross-module* ULID references) does not apply.
     */
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->string('nickname')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamp('linked_at');
            $table->timestamps();

            // One provider identity backs exactly one account. Without this, a
            // second account could claim the same SteamID and both would log in
            // as "the Steam user", which is account confusion rather than a
            // duplicate row.
            $table->unique(['provider', 'provider_user_id']);

            // And one account holds at most one identity per provider, so
            // "unlink Steam" is unambiguous.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
