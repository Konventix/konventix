<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The linked-account signup rule on a discipline (design §5.6, operator
 * decision 2026-09-22). It holds the provider key Identity uses (`steam`),
 * which everyone who enters or joins a team for a competition of this
 * discipline must have linked. Null means there is no requirement, which is
 * what every existing discipline gets.
 *
 * A string rather than a database enum: the closed set lives in
 * `Enums\LinkedProvider`, and adding a provider should not need a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disciplines', function (Blueprint $table): void {
            $table->string('requires_linked_provider', 32)->nullable()->after('allow_draws');
        });
    }

    public function down(): void
    {
        Schema::table('disciplines', function (Blueprint $table): void {
            $table->dropColumn('requires_linked_provider');
        });
    }
};
