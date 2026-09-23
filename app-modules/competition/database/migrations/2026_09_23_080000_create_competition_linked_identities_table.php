<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which provider accounts a person has linked, as Competition knows it
 * (design §5.6). This is what a discipline's `requires_linked_provider` is
 * checked against. Identity owns `social_identities`, and this module may not
 * read it (ADR-0004), so `Listeners\ProjectLinkedIdentity` projects the fact
 * from Identity's contract events.
 *
 * Two deliberate absences:
 *
 * - **No `brand_id`.** A linked Steam account is a fact about the person, and
 *   accounts are operator-wide (ADR-0016). `competition_participant_profiles`
 *   leaves out the brand for the same reason.
 * - **No `provider_user_id`.** The rule only asks whether a link exists. A
 *   SteamID64 identifies a natural person, and this module has no use for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_linked_identities', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Identity's user — another module, so a plain ULID with no FK
            // (ADR-0014).
            $table->ulid('user_id');

            // Identity's provider key (`steam`).
            $table->string('provider', 32);

            $table->timestamp('linked_at');

            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_linked_identities');
    }
};
