<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signup (D-CMP-17, design §5): captains and invite codes (§5.2), the
     * public interested list (§5.3), incomplete rosters (§5.4) and the
     * reason an entrant left (§5.5). Plus `competition_participant_profiles`
     * (§10), the read model every participant page renders names from —
     * Identity owns users, so the name arrives by event and never by import
     * (ADR-0004).
     *
     * Three details worth the ink:
     *
     * - **The invite code excludes `0/O` and `1/I`.** It is read aloud on
     *   Discord and typed from a phone; an alphabet that cannot be misheard
     *   is worth thirty-two characters instead of thirty-six.
     * - **The partial index grows a third state.** `entrants_one_live_per_team`
     *   guarded `registered`/`checked_in` only, which was right while those
     *   were the only states holding a slot. `incomplete` now holds one too
     *   (§5.4), so a team with an unfilled roster could otherwise enter the
     *   same competition twice and appear in the bracket twice once it was
     *   filled. Postgres cannot alter a partial index's predicate, so it is
     *   dropped and recreated.
     * - **`competition_participant_profiles` is not brand-owned.** Accounts
     *   are operator-global (ADR-0016) and so is the profile of record
     *   (ADR-0051 §6); a brand column here would force a row per brand for
     *   one display name.
     */
    public function up(): void
    {
        Schema::table('competition_teams', function (Blueprint $table): void {
            // Nullable first: existing rows are backfilled below, and only
            // then does the column become required.
            $table->char('invite_code', 8)->nullable()->after('name');
        });

        $this->backfillInviteCodes();

        Schema::table('competition_teams', function (Blueprint $table): void {
            $table->char('invite_code', 8)->nullable(false)->change();
            $table->unique(['brand_id', 'invite_code']);
        });

        Schema::table('competition_team_members', function (Blueprint $table): void {
            // `captain` | `member`. Exactly one captain per team, assigned at
            // creation and rotated to the longest-standing member when the
            // captain leaves (§5.2, LanCore's rule kept).
            $table->string('role', 16)->default('member')->after('user_id');
        });

        $this->backfillCaptains();

        Schema::table('entrants', function (Blueprint $table): void {
            // Why the entrant left: `incomplete_roster` when the organizer
            // declined an unfilled team at signup close (§5.4), otherwise who
            // pulled it (§5.5). Held as a string rather than free text so the
            // console can group by it.
            $table->string('withdrawal_reason', 32)->nullable()->after('checked_in_at');
            $table->timestamp('left_at')->nullable()->after('withdrawal_reason');
        });

        DB::statement('DROP INDEX IF EXISTS entrants_one_live_per_team');
        DB::statement(
            'CREATE UNIQUE INDEX entrants_one_live_per_team
             ON entrants (competition_id, competition_team_id)
             WHERE state IN (\'incomplete\', \'registered\', \'checked_in\')'
        );

        // The public "I would play" list (§5.3). Its audience is the seed of
        // the per-competition chat (#116): interested + entered.
        Schema::create('competition_interests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('competition_id')->constrained()->cascadeOnDelete();

            // Identity owns users — plain ULID, no FK (ADR-0014).
            $table->ulid('user_id');

            $table->string('note', 140)->nullable();

            $table->timestamps();

            $table->unique(['competition_id', 'user_id']);
            $table->index(['brand_id', 'competition_id']);
        });

        // Names on every participant and public page (§10), projected from
        // Identity's `UserProfileUpdated`. Keyed by the user's own ULID: the
        // key is assigned from the payload, never generated.
        Schema::create('competition_participant_profiles', function (Blueprint $table): void {
            $table->ulid('user_id')->primary();

            $table->string('display_name');
            $table->string('username');
            $table->string('visibility', 16)->default('internal');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_participant_profiles');
        Schema::dropIfExists('competition_interests');

        DB::statement('DROP INDEX IF EXISTS entrants_one_live_per_team');
        DB::statement(
            'CREATE UNIQUE INDEX entrants_one_live_per_team
             ON entrants (competition_id, competition_team_id)
             WHERE state IN (\'registered\', \'checked_in\')'
        );

        Schema::table('entrants', function (Blueprint $table): void {
            $table->dropColumn(['withdrawal_reason', 'left_at']);
        });

        Schema::table('competition_team_members', function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        Schema::table('competition_teams', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'invite_code']);
            $table->dropColumn('invite_code');
        });
    }

    /**
     * One code per existing team, unique within its brand. Generated here
     * rather than through the model so the migration stays readable on its
     * own — `CompetitionTeam::ALPHABET` is the same thirty-two characters and
     * a test pins the two together.
     */
    private function backfillInviteCodes(): void
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $taken = [];

        DB::table('competition_teams')->orderBy('id')->each(function (object $team) use ($alphabet, &$taken): void {
            do {
                $code = '';

                for ($i = 0; $i < 8; $i++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }

                $key = $team->brand_id.':'.$code;
            } while (isset($taken[$key]));

            $taken[$key] = true;

            DB::table('competition_teams')->where('id', $team->id)->update(['invite_code' => $code]);
        });
    }

    /**
     * Every existing team gets exactly one captain: its longest-standing
     * member, which is the same rule rotation follows (§5.2).
     */
    private function backfillCaptains(): void
    {
        DB::statement(<<<'SQL'
            UPDATE competition_team_members
            SET role = 'captain'
            WHERE id IN (
                SELECT DISTINCT ON (competition_team_id) id
                FROM competition_team_members
                ORDER BY competition_team_id, created_at, id
            )
        SQL);
    }
};
