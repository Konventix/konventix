<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this operator writes dates and times (#242).
 *
 * Operator level rather than brand level: an invoice, a dunning mail and a
 * console table all name the same moment under one legal sender, and a
 * brand-level knob would let two of them disagree about how — with nobody
 * able to say which is right.
 *
 * Stored as the pattern an operator reads on the settings page rather than a
 * PHP format string, because the same value is handed to the browser as a
 * shared prop; `Enums\DateFormat` and `Enums\TimeFormat` own the translation
 * to `date()` on one side and `Intl` parts on the other. The defaults are the
 * conventions the instance already prints on its invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('date_format', 16)->default('DD.MM.YYYY');
            $table->string('time_format', 8)->default('24h');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn(['date_format', 'time_format']);
        });
    }
};
