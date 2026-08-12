<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Konventix\Identity\Enums\AccountState;

/**
 * The remaining slim account fields (SPEC.md "Owns" — User): locale and
 * timezone belong to the account rather than any domain process, `state`
 * carries the lifecycle (active/locked — anonymized is a later addition, see
 * TODO.md), and `preferences` is a typed JSON column (ADR-0009 — a JSON
 * column is acceptable only behind a typed cast; see
 * `Data\AccountPreferencesData`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale')->default('en')->after('password');
            $table->string('timezone')->default('UTC')->after('locale');
            $table->string('state')->default(AccountState::Active->value)->after('timezone');
            $table->json('preferences')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['locale', 'timezone', 'state', 'preferences']);
        });
    }
};
