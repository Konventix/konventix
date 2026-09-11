<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of a person's display name (design doc §13,
     * 2026-09-11 amendment "the holder-name fallback drops the billing
     * recipient"). The ticket's holder band (§4.2) falls back from the
     * holder's own name to "Unassigned" — the middle step the design
     * originally named, the registration's billing recipient, is not
     * reachable from this module (ADR-0004) — so this table is what *is*
     * reachable: the linked holder's own display name, projected from
     * Identity's account events. Same shape as `registration_registrants`
     * and `messaging_recipients`, mirroring `Listeners\ProjectPerson`.
     *
     * The primary key IS the account's own ULID
     * (`Konventix\Identity\Events\UserRegistered::$userId`), not a
     * generated one: the row is a projection of a foreign entity, not an
     * entity of its own (ADR-0004). Not brand-scoped: accounts are
     * operator-global (ADR-0016) — a person registered under one brand is
     * still the same person when a different brand's entrance API looks
     * them up as a holder, exactly like `registration_registrants` and
     * `messaging_recipients`.
     *
     * Backfilled by the existing `identity:replay-profiles`
     * (`UserProfileUpdated` carries the full current profile) — no new
     * replay command needed.
     */
    public function up(): void
    {
        Schema::create('admission_people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->string('email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_people');
    }
};
