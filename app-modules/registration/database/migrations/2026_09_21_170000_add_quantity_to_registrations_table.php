<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 19 (bugfix round 2026-09-21): how many units of the offer this
     * contractual act buys. A buyer-chosen fact of the Registration, set at
     * submission and never edited afterwards — not in the cart, not on the
     * order (Commerce SPEC "its quantity is fixed by the registration
     * itself" still holds, it is simply no longer always one).
     *
     * Default 1, so every existing row and every type that does not opt into
     * a larger quantity behaves exactly as it did: one quota unit consumed,
     * one settlement line of quantity one, grants as declared.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
