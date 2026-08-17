<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A brand's own object storage for its media (ADR-0043 point 6), the same
     * shape per-brand mail transports take (ADR-0028): storage that belongs to
     * the brand is brand data, not deployment config.
     *
     * A brand with no row here uses the deployment's configured media disk,
     * which is the normal case — this table exists for the brand that brings
     * its own bucket.
     *
     * Non-secret settings are columns so the console can list them without
     * decrypting anything; the access key and secret live in one encrypted
     * blob.
     */
    public function up(): void
    {
        Schema::create('brand_storage_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One row per brand, and it dies with the brand.
            $table->foreignUlid('brand_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('bucket');
            $table->string('region')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('prefix')->default('media');
            $table->boolean('use_path_style_endpoint')->default(false);

            // Public delivery is per bucket, exactly as it is for the
            // deployment's own disks: a claim the operator makes about how the
            // bucket was created, which `storage:doctor` verifies.
            $table->boolean('public_direct')->default(false);
            $table->string('url')->nullable();

            // Crypt ciphertext, not JSON — hence `text`.
            $table->text('credentials');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_storage_settings');
    }
};
