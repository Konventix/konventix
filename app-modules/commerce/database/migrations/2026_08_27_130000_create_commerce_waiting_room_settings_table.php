<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-brand waiting-room settings (ADR-0047 point 5): how many
     * arrivals `AdmitFromWaitingRoom` lets through per call, and how long
     * an admitted ticket keeps its place before `CommerceWaitingRoomGate`
     * sends it back to the queue. One row per brand — `unique('brand_id')`
     * — `WaitingRoomSettings::forBrand()` hands back an unsaved defaulted
     * instance when nothing was configured yet, the same idiom as
     * `BookkeepingSettings::forOperator()`.
     */
    public function up(): void
    {
        Schema::create('waiting_room_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('admit_per_interval')->default(50);
            $table->unsignedSmallInteger('admission_ttl_minutes')->default(30);
            $table->timestamps();
            $table->unique('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_room_settings');
    }
};
