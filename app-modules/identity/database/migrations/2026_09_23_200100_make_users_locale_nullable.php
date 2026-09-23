<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0068 §4: null follows the brand. Every existing 'en' was written by
 * the column default, not chosen by the person, so it is reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale')->nullable()->default(null)->change();
        });

        DB::table('users')->where('locale', 'en')->update(['locale' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('locale')->update(['locale' => 'en']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale')->nullable(false)->default('en')->change();
        });
    }
};
