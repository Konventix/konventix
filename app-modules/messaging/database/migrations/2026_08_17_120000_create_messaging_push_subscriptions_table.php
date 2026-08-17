<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web-push device subscriptions (ADR-0041 point 7, D-MSG-02): one row
     * per (recipient, endpoint). Not brand-owned — a browser subscription
     * belongs to a person's device, and accounts are operator-global
     * (ADR-0016); the message that travels through it still carries its
     * own brand. Endpoints are pruned when the push service reports them
     * gone (404/410).
     */
    public function up(): void
    {
        Schema::create('messaging_push_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('recipient_id');

            // Push-service URL; opaque and long, but well under btree's
            // index ceiling.
            $table->string('endpoint', 1000);

            // Client keys from PushSubscription.getKey() — public values,
            // useless without the push service accepting the endpoint.
            $table->string('p256dh', 255);
            $table->string('auth', 255);

            $table->string('content_encoding', 32)->default('aes128gcm');

            $table->timestamps();

            $table->unique(['recipient_id', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_push_subscriptions');
    }
};
