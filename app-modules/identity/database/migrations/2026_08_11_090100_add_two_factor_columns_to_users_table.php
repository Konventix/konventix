<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fortify's own migration (vendor/laravel/fortify/database/migrations) is not
 * loaded — migrations live inside the owning module (.ai/rules/models.md) — so
 * this is a straight port of its columns, required by
 * `Features::twoFactorAuthentication()` and the `TwoFactorAuthenticatable`
 * trait on `User`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
