<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #218 (#216, draft i): the offer gets a sales window of its own. Until
     * now a public type was buyable the moment it existed — there was no date
     * column on `registration_types` at all.
     *
     * Both columns are nullable and null means "no bound", so every existing
     * type keeps behaving exactly as it did.
     *
     * These are **instants**, not wall-clock times. ADR-0027 stores the
     * event's own schedule wall-clock because "doors open 10:00" is a promise
     * about a local clock; a sales window is a deadline — the same shape as
     * `carts.expires_at` or `products.published_at` — and it must be
     * enforceable in Commerce, which never learns the event's timezone
     * (ADR-0004). Registration does not hold it either: its event read model
     * projects name, slug and `archived_at` and nothing more.
     */
    public function up(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->timestamp('sales_start')->nullable();
            $table->timestamp('sales_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registration_types', function (Blueprint $table): void {
            $table->dropColumn(['sales_start', 'sales_end']);
        });
    }
};
