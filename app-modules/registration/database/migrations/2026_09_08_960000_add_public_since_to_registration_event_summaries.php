<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this event became publicly visible, projected from Event's
     * contract events (GitLab #240 review finding 1).
     *
     * `/shop` lists a brand's registration types across every event, and it
     * had no way to tell a draft event from a live one: `EventCreated`
     * projects a summary the moment an organizer starts next year's
     * convention, so the event's name, its ticket names and its prices were
     * on a public page before the announcement. Every other public surface
     * is gated — `EventLandingController::show()` on
     * `Event::isVisibleOnLanding()`, the public index on `listedPublicly()`
     * — and Registration may not read that state (ADR-0004), so it has to
     * hold its own copy.
     *
     * Named `public_since` rather than `published_at`: it is the projection
     * of "has left `Draft`", which `EventAnnounced` reaches as well as
     * `EventPublished`, and a column called `published_at` that is set for
     * an `announced` event would be a lie about Event's own column of that
     * name.
     */
    public function up(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->timestamp('public_since')->nullable()->after('slug');
        });

        // One-off backfill for summaries that already exist: without it every
        // live event's offers would vanish from `/shop` until it happened to
        // be republished. A migration is deployment infrastructure rather
        // than module code — this is the one place Registration reads
        // `events`, and it reads it once, to seed a projection that every
        // later change arrives at through the contract events (ADR-0004).
        // `published_at` for an event that went through `Published`, its
        // creation time for one that only ever reached `announced` and has
        // no such timestamp of its own.
        DB::statement(<<<'SQL'
            UPDATE registration_event_summaries AS s
            SET public_since = COALESCE(e.published_at, e.created_at)
            FROM events AS e
            WHERE e.id = s.event_id
              AND e.state <> 'draft'
        SQL);
    }

    public function down(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->dropColumn('public_since');
        });
    }
};
