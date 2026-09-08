<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this event may be *listed*, not merely reached (branch review
     * blocker 2 on GitLab #240).
     *
     * `public_since` projects `Event::isVisibleOnLanding()`, which is the
     * right rule for a page addressed by one event and the wrong one for
     * `/shop`, a brand-wide listing. Event's own listing predicate is
     * stricter and says why in as many words
     * (`Event::scopeListedPublicly()`): "`Unlisted` means reachable by
     * direct link and *not* listed. An unlisted event that appeared in the
     * index would make the word mean nothing." An announced `Unlisted`
     * event — an invite-only track, a staff registration — had its name,
     * its ticket names and its prices enumerated on a public page.
     *
     * The value is `EventVisibility`'s backing string, carried as a scalar
     * on `EventCreated`/`EventDetailsUpdated` (ADR-0004). Nullable, and the
     * shop gate matches `'public'` only, so a row this projection has not
     * heard about yet is hidden rather than wrongly listed.
     *
     * Backfilled once from `events`, the same one-off shape
     * `2026_09_08_960000` established for `public_since`: a migration is
     * deployment infrastructure, this is the only place Registration reads
     * `events`, and every later change arrives through the contract events.
     */
    public function up(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->string('visibility')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE registration_event_summaries AS s
            SET visibility = e.visibility
            FROM events AS e
            WHERE e.id = s.event_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
