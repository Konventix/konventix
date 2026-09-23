<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0068 §4: the operator states the instance default, a brand may
 * override it (null inherits). Existing rows keep rendering English, which
 * is what they rendered before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('locale', 12)->default('en')->after('time_format');
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->string('locale', 12)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
