<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The lifecycle (D-CMP-15, design §4) and the fields the competition form
     * owns (design §9): description and rules, the participant limit, the
     * start time, and the two knob pairs — check-in (§6) and results (§8.1).
     *
     * **`format` is dropped** (design §7.1). The format belongs to the stage,
     * which has carried one since 10b; the column on `competitions` was 10a's
     * single-flat-list shape and nothing read it but the factory and the demo
     * seeder. `GenerateBracket` has always resolved the generator from
     * `competition_stages.format`, so the drop reaches no engine code.
     *
     * `starts_at` is an instant, not an ADR-0027 wall clock: the console
     * types it in the reader's zone and the Data object anchors it to UTC
     * before it reaches this column. Agenda reads it — through
     * `CompetitionPublished` / `CompetitionRescheduled`, never this table —
     * for the published programme, so the name is a cross-project contract.
     *
     * `status` is backfilled from `published_at`, which stays as the
     * timestamp of the publish transition rather than as the state itself.
     */
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->string('status', 24)->default('draft')->after('slug');

            $table->text('description')->nullable()->after('status');
            $table->text('rules')->nullable()->after('description');

            // Null means "no limit"; a zero limit would be a competition
            // nobody may enter, which is what `Draft` is for.
            $table->unsignedSmallInteger('max_entrants')->nullable()->after('rules');

            $table->timestamp('starts_at')->nullable()->after('signup_closes_at');

            // Check-in knobs (D-CMP-18, design §6).
            $table->string('check_in_mode', 16)->default('none')->after('starts_at');
            $table->unsignedSmallInteger('check_in_opens_minutes_before')->default(60)->after('check_in_mode');
            $table->boolean('requires_event_check_in')->default(false)->after('check_in_opens_minutes_before');

            // Result knobs (D-CMP-19, design §8.1).
            $table->string('result_submission_mode', 16)->default('desk_only')->after('requires_event_check_in');
            $table->string('proof_policy', 16)->default('none')->after('result_submission_mode');

            $table->index(['brand_id', 'event_id', 'status']);
        });

        DB::table('competitions')->whereNotNull('published_at')->update(['status' => 'published']);

        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropColumn('format');
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->string('format', 32)->default('single_elimination');
        });

        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'event_id', 'status']);

            $table->dropColumn([
                'status',
                'description',
                'rules',
                'max_entrants',
                'starts_at',
                'check_in_mode',
                'check_in_opens_minutes_before',
                'requires_event_check_in',
                'result_submission_mode',
                'proof_policy',
            ]);
        });
    }
};
