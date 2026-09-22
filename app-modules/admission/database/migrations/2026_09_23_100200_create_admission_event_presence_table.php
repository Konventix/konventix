<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who has been let in to which event (D-HUB-01, design §4.1). Written
     * only from Admission's own check-ins; read by nobody outside this
     * module — Platform learns of it through `ParticipantAdmitted` and its
     * siblings (ADR-0004).
     */
    public function up(): void
    {
        Schema::create('admission_event_presence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
            // Event and Identity are other modules — plain ULIDs (ADR-0014).
            $table->ulid('event_id');
            $table->ulid('user_id');
            $table->foreignUlid('admission_id')->constrained('admissions')->cascadeOnDelete();
            $table->timestampTz('first_admitted_at');
            $table->timestampTz('last_check_in_at');
            $table->string('last_direction', 3);
            $table->timestampTz('window_ends_at')->nullable();
            $table->boolean('hub_access')->default(true);
            $table->string('access_ended_reason', 16)->nullable();
            $table->string('day_label')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['hub_access', 'window_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_event_presence');
    }
};
