<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 30 / D-REG-18 (spec §4.2): which conditions a type demands.
     *
     * A pivot rather than a JSON list on the type, for two reasons: one
     * condition is reusable across four student types, and a binding
     * resolution has to be able to join it.
     *
     * `position` is the order the public form renders them in. Both sides are
     * owned by this module, so real FKs are fine (ADR-0014); a condition with
     * assertions is archived rather than deleted, so the cascade here only
     * ever fires for a condition nobody ever asserted.
     *
     * Deliberately **not** a frozen axis (D-REG-19): the set stays editable
     * after the type has sold, because the evidence lives in the assertion's
     * snapshot and not in this table.
     */
    public function up(): void
    {
        Schema::create('registration_type_entry_condition', function (Blueprint $table): void {
            $table->foreignUlid('registration_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('entry_condition_id')->constrained('registration_entry_conditions')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->primary(['registration_type_id', 'entry_condition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_type_entry_condition');
    }
};
