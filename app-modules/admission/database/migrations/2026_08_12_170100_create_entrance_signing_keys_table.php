<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ed25519 keypairs that sign KVX1 admission tokens (ADR-0033).
     *
     * Retirement and expiry are separate on purpose. A retired key stops
     * *signing* immediately but must keep *verifying* until every token it
     * signed has expired — otherwise rotating a key would lock out everyone
     * already holding a ticket. `expires_at` is when it finally drops out of
     * the published key set.
     */
    public function up(): void
    {
        Schema::create('entrance_signing_keys', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. Keys never cross brands: one brand's
            // scanner must not be able to verify another brand's tokens.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // The `kid` in `KVX1.<kid>.<body>.<sig>` — short, opaque, and the
            // only key material that ever appears in a token.
            $table->string('kid', 32)->unique();

            // Public half is served openly; private half is encrypted at rest
            // (ADR-0028's pattern for per-brand secrets).
            $table->text('public_key');
            $table->text('private_key');

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['brand_id', 'retired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrance_signing_keys');
    }
};
