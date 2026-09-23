<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0068 §4: the operator states the instance default, a brand may
 * override it (null inherits). A new instance starts in English (the column
 * default); an operator that exists before this migration is set to German,
 * because that is what its invoices, credit notes, reminders and mails were
 * rendered in until now (ADR-0030's DACH framing) — leaving it at `en` would
 * freeze the first post-rollout documents in a language the operator never
 * chose. The console lets the operator change it afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('locale', 12)->default('en')->after('time_format');
        });

        DB::table('operators')->update(['locale' => 'de']);

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
