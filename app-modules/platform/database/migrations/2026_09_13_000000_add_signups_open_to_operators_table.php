<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this operator lets strangers create accounts (hotfix 2026-09-13).
 *
 * Operator level rather than brand level: an account is the person's, not the
 * brand's (ADR-0016 makes the brand a membership on top of it), and a signup
 * on one brand host is an account on every brand of the operator. A brand
 * that wanted to admit new people while a sister brand did not would be
 * admitting them to both anyway.
 *
 * Defaults to open, which is what every instance did before the column
 * existed. The instance-wide cap `SIGNUPS_ENABLED` (config/platform.php) sits
 * above it: the environment can force signups closed, the console can only
 * close them further.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->boolean('signups_open')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('signups_open');
        });
    }
};
