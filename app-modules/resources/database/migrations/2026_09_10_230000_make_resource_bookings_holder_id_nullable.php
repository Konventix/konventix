<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erasure nulls the `holder_id` of an operator's own reservations (design
 * §9.5): the row stays as occupancy history, the pointer at the person
 * goes. Registration-held bookings are never nulled — their holder is the
 * registration, which Registration tombstones itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->ulid('holder_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('resource_bookings', function (Blueprint $table): void {
            $table->ulid('holder_id')->nullable(false)->change();
        });
    }
};
