<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resources' read model of admissions (#292 §6.1, ADR-0004): a seat is
     * bound to an admission from here on, and the picker has to know which
     * admissions a registration has, who holds each and who bought it —
     * without reading Admission's table. Projected off `AdmissionIssued`,
     * `AdmissionHolderAssigned`, `AdmissionHolderUnassigned`,
     * `AdmissionRevoked` and, for rows older than this table,
     * `AdmissionOwnershipAnnounced` (`admission:announce-ownership`).
     *
     * `id` is the admission's own ULID. `registration_id` is nullable —
     * a staff admission has none and never seats this way.
     */
    public function up(): void
    {
        Schema::create('resource_admissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            $table->ulid('registration_id')->nullable();
            $table->ulid('event_id');
            $table->ulid('holder_user_id')->nullable();
            $table->ulid('buyer_user_id')->nullable();
            $table->string('state', 16);
            $table->timestamps();

            $table->index(['brand_id', 'registration_id']);
            $table->index(['brand_id', 'holder_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_admissions');
    }
};
