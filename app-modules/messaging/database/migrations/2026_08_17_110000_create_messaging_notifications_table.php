<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The in-app inbox (ADR-0041 point 6): the in-app channel's delivery
     * *is* this row. Brand-owned — a notification is something a brand said
     * to a person, and the bell on a brand's site shows that brand's rows.
     * Shaped for the D-CPL-01 twelve-month purge alongside the delivery
     * log.
     */
    public function up(): void
    {
        Schema::create('messaging_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->ulid('recipient_id');

            // The delivery-log row this delivery belongs to.
            $table->ulid('message_id');

            $table->string('topic_key')->nullable();

            $table->string('title', 500);
            $table->text('body');
            $table->string('url', 2000)->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The bell reads unread-first per recipient; the purge walks by
            // brand and age.
            $table->index(['recipient_id', 'read_at']);
            $table->index(['recipient_id', 'created_at']);
            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_notifications');
    }
};
