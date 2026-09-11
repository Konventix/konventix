<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `AccountState::Merged` (#81): which account absorbed this one, for the
 * account editing screen and for anyone auditing the tombstone later. A
 * plain ulid column with no foreign-key constraint, deliberately — this is
 * Identity's own table, so ADR-0014's cross-module rule does not strictly
 * apply, but a FK here would forbid the one thing GDPR erasure has to be
 * able to do: remove the row it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('merged_into_user_id')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('merged_into_user_id');
        });
    }
};
