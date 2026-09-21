<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-event attendee-list switch (ADR-0064 §1): `off`, `members` or
 * `public` — `Enums\AttendeeListVisibility`. Defaults every existing event
 * to `off`: an operator turns the list on per event, never the other way
 * round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('attendee_list_visibility')->default('off')->after('reentry_policy');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('attendee_list_visibility');
        });
    }
};
