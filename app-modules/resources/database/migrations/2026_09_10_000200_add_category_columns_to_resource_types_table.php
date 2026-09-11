<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resource type is the seating "Kategorie" (ADR-0060 point 3). It gains
     * what the picker and the editor need to draw it: a description, a
     * semantic colour token (never a hex, ADR-0008), a lucide symbol so state
     * is never colour alone (ADR-0055), an order, and the default shape the
     * editor gives a new place of this type.
     */
    public function up(): void
    {
        Schema::table('resource_types', function (Blueprint $table): void {
            $table->text('description')->nullable();

            // A `CategoryToken` value; rendered as the CSS variable of that
            // name. `chart-1` is the first series colour of the theme.
            $table->string('token', 32)->default('chart-1');

            // A lucide icon key. Nullable on the row, mandatory in the picker.
            $table->string('symbol', 32)->nullable();

            $table->smallInteger('sort_order')->default(0);

            // A `ShapeData` behind laravel-data's typed cast (ADR-0009).
            $table->jsonb('default_shape')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resource_types', function (Blueprint $table): void {
            $table->dropColumn(['description', 'token', 'symbol', 'sort_order', 'default_shape']);
        });
    }
};
