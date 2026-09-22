<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Results reported by participants, the dispute they can turn into, and
     * the history every step of a match lands in (D-CMP-19, design §8).
     *
     * **`match_result_reports`** is what a side *says* happened, which is a
     * different fact from what the match *records*. §8.2 needs both: a report
     * from one side alone finalises nothing, two agreeing reports finalise
     * the match, and two disagreeing reports are a dispute in which **both
     * stay** — the desk has to see what each side claimed, which a single
     * overwritten "reported result" column could not show.
     *
     * `scores` is keyed by **slot position**, not by entrant, exactly as §8.2
     * writes it. A correction re-routes entrants through the progression
     * graph (§8.5), so the entrant in slot 2 of a match is not stable over the
     * life of that match, while the slot is. Comparing two reports is then a
     * comparison of two maps with the same keys, and `RecordMatchResult`'s
     * entrant-keyed form is resolved from the slots at the moment of
     * recording.
     *
     * One report per side, enforced by a unique index: a side that reports
     * twice has changed its mind, not made a second claim, so the row is
     * replaced. `updated_at` is therefore meaningful and the table carries
     * both timestamps, where §8.2's sketch named only `created_at`.
     *
     * **`competition_match_history`** is #116's "nachvollziehbare Historie pro
     * Match" without the chat. Append-only by construction — nothing updates
     * a row, except GDPR erasure nulling `actor_user_id` — and read in
     * `created_at` order, so it carries no `updated_at`.
     * `payload` is typed per kind by `MatchHistoryPayload` (ADR-0009); the
     * column is json because thirteen kinds with different fields is what a
     * timeline *is*, not a settings blob.
     *
     * **Three columns on `competition_matches`.** `reported_at` is §8.2's
     * "the match gains `reported_at`"; `disputed_at` is §8.2's `disputed_at`;
     * `dispute_resolved_at` is the counterpart §8.4 implies but does not name,
     * and it exists rather than nulling `disputed_at` because "there was a
     * dispute here" is a fact worth keeping after it is settled — and because
     * §13's `competition_disputes_open` metric and its 30-minute alert are
     * exactly `disputed_at not null and dispute_resolved_at null`.
     *
     * **Three columns on `competition_score_entries`.** §8.3 gives an
     * organizer two answers to a submitted score, and only one of them had
     * somewhere to live: verify, or "rejects it with a note". A rejected entry
     * keeps its value and its proof and gains a reason, because the
     * participant has to be able to see what was wrong with it and resubmit
     * — deleting the row would tell them nothing. `note` stays the
     * *submitter's* note; the organizer's lands in `rejection_note`.
     *
     * Cross-module ULIDs carry no foreign key (ADR-0014): Identity owns users
     * and Platform owns the media library. `brand_id` and this module's own
     * tables do.
     */
    public function up(): void
    {
        Schema::create('match_result_reports', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Same module — real FKs allowed (ADR-0014).
            $table->foreignUlid('competition_match_id')->constrained('competition_matches')->cascadeOnDelete();
            $table->foreignUlid('entrant_id')->constrained('entrants')->cascadeOnDelete();

            // Nullable because GDPR erasure nulls it and keeps the row
            // (`CompetitionGdprHandler`): the side's claim is the match's
            // history, the person who filed it is not.
            $table->ulid('reported_by_user_id')->nullable();

            // slot position => score. See the class note above.
            $table->json('scores');

            // Platform media (ADR-0035), when the competition's proof policy
            // takes one.
            $table->ulid('proof_media_id')->nullable();

            $table->timestamps();

            // One claim per side. A second report replaces the first.
            $table->unique(['competition_match_id', 'entrant_id']);
        });

        Schema::create('competition_match_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competition_match_id')->constrained('competition_matches')->cascadeOnDelete();

            $table->string('kind', 32);

            // Null for anything the system did on its own — a cascade that
            // voids a downstream match names the correcting organizer, but a
            // scheduler tick names nobody — and for an actor who has since
            // been erased (`CompetitionGdprHandler`).
            $table->ulid('actor_user_id')->nullable();

            $table->json('payload');

            // Append-only: the one read is "this match's timeline, oldest
            // first", and nothing but GDPR erasure ever updates a row.
            $table->timestamp('created_at')->nullable();

            $table->index(['competition_match_id', 'created_at']);
        });

        Schema::table('competition_matches', function (Blueprint $table): void {
            $table->timestamp('reported_at')->nullable()->after('finished_event_emitted_at');
            $table->timestamp('disputed_at')->nullable()->after('reported_at');
            $table->timestamp('dispute_resolved_at')->nullable()->after('disputed_at');
        });

        Schema::table('competition_score_entries', function (Blueprint $table): void {
            $table->timestamp('rejected_at')->nullable()->after('verified_by_user_id');
            $table->ulid('rejected_by_user_id')->nullable()->after('rejected_at');
            $table->string('rejection_note')->nullable()->after('rejected_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('competition_score_entries', function (Blueprint $table): void {
            $table->dropColumn(['rejected_at', 'rejected_by_user_id', 'rejection_note']);
        });

        Schema::table('competition_matches', function (Blueprint $table): void {
            $table->dropColumn(['reported_at', 'disputed_at', 'dispute_resolved_at']);
        });

        Schema::dropIfExists('competition_match_history');
        Schema::dropIfExists('match_result_reports');
    }
};
