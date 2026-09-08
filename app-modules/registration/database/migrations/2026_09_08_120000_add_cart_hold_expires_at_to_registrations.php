<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `cart_hold_expires_at`: Registration's knowledge that this
     * registration's `instant` settlement went into the registrant's cart
     * rather than into an order, and until when the hold there lasts —
     * learned from Commerce's `RegistrationLinePlacedInCart` (#210), the
     * same shape `invoiced_at` already has for `InvoiceIssued` (ADR-0004:
     * a read model fed by events, never a foreign table read).
     *
     * The lifecycle mail is what needs it: with `commerce:shop` on and any
     * gate but `on_payment`, the registrant is not in the shop when the
     * line appears, so the confirmation mail is the only push that can name
     * the cart and its twenty-minute reservation.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->timestamp('cart_hold_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn('cart_hold_expires_at');
        });
    }
};
