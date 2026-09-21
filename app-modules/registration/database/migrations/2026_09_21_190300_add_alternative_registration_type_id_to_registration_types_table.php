<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 30 / D-REG-21 (spec §4.2): the type to offer instead when
     * somebody will not tick the box — "Buy the regular ticket instead".
     *
     * It is used for that link and nothing else. It is not an ADR-0005 axis,
     * it grants nothing, and it may be set and cleared at any time.
     *
     * Same module, so a real FK is fine (ADR-0014); `nullOnDelete` because a
     * deleted alternative must leave the conditional type sellable rather
     * than take it down with it. Self-referencing, so it is declared in a
     * second `Schema::table()` call — see `.ai/rules/models.md`; here the
     * table already exists, so one call is enough.
     */
    public function up(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->foreignUlid('alternative_registration_type_id')
                ->nullable()
                ->constrained('registration_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alternative_registration_type_id');
        });
    }
};
