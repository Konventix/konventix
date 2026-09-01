<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `profile_visibility` (ADR-0052 §1): defaults every existing row to
 * `internal`, which is exactly how ADR-0051 §7's members-only rule already
 * behaved for all of them — no backfill question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_visibility')->default('internal')->after('social_links');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profile_visibility');
        });
    }
};
