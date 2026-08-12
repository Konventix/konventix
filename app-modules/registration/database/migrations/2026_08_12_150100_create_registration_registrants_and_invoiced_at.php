<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registrant read model (projected from Identity's events — the
     * organizer list needs a display name without importing Identity's
     * model, ADR-0004) and `invoiced_at`: Registration's knowledge that an
     * invoice exists for the act, learned from Commerce's `InvoiceIssued`.
     * ADR-0036 freezes the billing party from that moment — post-invoice
     * transfer is a credit-note + re-issue flow (Slice 7).
     */
    public function up(): void
    {
        Schema::create('registration_registrants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::table('registrations', function (Blueprint $table): void {
            $table->timestamp('invoiced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn('invoiced_at');
        });

        Schema::dropIfExists('registration_registrants');
    }
};
