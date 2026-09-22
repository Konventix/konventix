<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projection of Admission's participant events (design §5.1); an
     * accelerator, rebuildable by replay. Which running events a member is
     * admitted to on a brand, with the display data the hub pill needs, so
     * the shell answers from one cache read and never touches
     * `admission_event_presence` (ADR-0004). `event_id`, `user_id` and
     * `admission_id` are other modules' ULIDs — no FK constraints (ADR-0014).
     */
    public function up(): void
    {
        Schema::create('platform_hub_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->ulid('event_id');
            $table->ulid('user_id');
            $table->ulid('admission_id');
            $table->string('event_slug')->nullable();
            $table->string('event_name')->nullable();
            $table->string('day_label')->nullable();
            $table->timestampTz('admitted_at');
            $table->timestampTz('window_ends_at')->nullable();
            $table->boolean('on_site')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['brand_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_hub_access');
    }
};
