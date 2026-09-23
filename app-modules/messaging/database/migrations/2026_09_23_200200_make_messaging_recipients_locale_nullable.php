<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0068 §4: the recipient read model mirrors `users.locale`, where null
 * follows the brand. Identity reset every defaulted 'en' there
 * (`make_users_locale_nullable`); the projected copies came from the same
 * default, so they are reset too. `SendMessage` resolves the effective
 * locale at send time (§5) and does not read this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_recipients', function (Blueprint $table): void {
            $table->string('locale', 12)->nullable()->default(null)->change();
        });

        DB::table('messaging_recipients')->where('locale', 'en')->update(['locale' => null]);
    }

    public function down(): void
    {
        DB::table('messaging_recipients')->whereNull('locale')->update(['locale' => 'en']);

        Schema::table('messaging_recipients', function (Blueprint $table): void {
            $table->string('locale', 12)->nullable(false)->default('en')->change();
        });
    }
};
