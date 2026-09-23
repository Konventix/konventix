<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The event's own lifecycle state on Competition's copy of it (design §14,
 * "a draft event's tournaments are not public"): the public tournament pages
 * resolve only for an event the Event module itself would show, and Event
 * says which that is only through its lifecycle events. A plain string, as
 * Agenda carries it — Competition may import Event's `Events\` namespace and
 * nothing else (ADR-0004), so `EventLifecycleState` is out of reach.
 *
 * Null means "nobody has told us yet" and is refused; `event:replay-details
 * --with-state` backfills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_event_summaries', function (Blueprint $table): void {
            $table->string('state')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('competition_event_summaries', function (Blueprint $table): void {
            $table->dropColumn('state');
        });
    }
};
