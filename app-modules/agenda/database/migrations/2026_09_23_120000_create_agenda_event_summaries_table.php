<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agenda's read model of the five event facts the programme needs
     * (design §3, ADR-0004): the slug the public URL is resolved by, the
     * name the page is headed with, the timezone every wall clock on it is
     * read in (ADR-0027), the day set the switcher is built from, and the
     * event's lifecycle state, which decides whether the public page
     * resolves at all.
     *
     * Agenda may not read Event's tables, so it keeps its own copy,
     * projected from `EventCreated` / `EventDetailsUpdated` /
     * `EventRescheduled` / `EventDaysChanged` — exactly the way
     * `admission_event_summaries` and `resource_event_windows` do.
     *
     * Every column but the identity pair (`brand_id`, `event_id`) is
     * nullable: the fields arrive on different contract events over time,
     * so the table has to tolerate partial knowledge at any point.
     *
     * `days` is a JSON list of `{day_id, date, label}` in `sequence` order
     * — `ProgrammeBuilder::forEvent()`'s `$days` argument, stored in the
     * shape it is handed over in. It is not a table of its own because
     * nothing here ever queries a single day: the whole list is read at
     * once, replaced at once (`EventDaysChanged` always carries the
     * complete current set) and never joined against.
     *
     * The `(brand_id, slug)` index is the public page's lookup — the one
     * query a visitor's first request makes.
     */
    public function up(): void
    {
        Schema::create('agenda_event_summaries', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is another module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('slug')->nullable();
            $table->string('name')->nullable();
            $table->string('timezone')->nullable();

            // Event's own lifecycle, as a plain string: Agenda may import
            // Event's `Events\` namespace and nothing else (ADR-0004), so
            // `EventLifecycleState` is out of reach and the value is
            // carried as the scalar it already is on the wire. It decides
            // whether the *public* page resolves at all — a draft event's
            // programme must not be reachable by guessing a slug, while
            // its editor stays open, because that is where a programme is
            // built.
            $table->string('state')->nullable();

            $table->json('days')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
            $table->index(['brand_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_event_summaries');
    }
};
