<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #219: the undo window behind the cart's trash button.
     *
     * Removing a registration line used to delete the row and emit
     * `CartLineRemoved` in the same click, which cancelled the registration
     * behind it — quota released, seats released, the buyer mailed — three
     * seconds after they had confirmed it. An "undo" cannot honestly reverse
     * that, so nothing irreversible happens during the window any more: the
     * line is *soft*-removed instead. It keeps holding what it held, the
     * registration and its cart hold stay alive, and only the closing of the
     * window turns the removal into a cancellation.
     *
     * `removed_at` is that flag and that clock in one. `Cart::lines()`
     * filters it out, so every existing reader — the page, the totals, the
     * checkout, the cart badge — behaves exactly as it did when the row was
     * deleted; `Cart::removedLines()` is the one relation that sees them,
     * and it feeds the undo banner.
     *
     * Nullable with no default and no backfill: every line that exists today
     * is present, which is what null means.
     */
    public function up(): void
    {
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropColumn('removed_at');
        });
    }
};
