<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 30 (spec §4.3): the evidence — what a registrant actually
     * declared, in the wording they were shown, and what was later made of
     * it at the console or at the door.
     *
     * One row per `(registration, entry_condition)` regardless of
     * `registrations.quantity` (D-REG-20): the registrant asserts once, for
     * the contractual act. The door checks per person, because the label
     * rides on every admission.
     *
     * `assertion_text_snapshot` and `verification_snapshot` are why the
     * condition stays editable (T-6, D-REG-19): the text may be reworded, the
     * evidence may not change under the person who gave it — the same
     * principle as consenting to a *version* rather than to a policy.
     *
     * The FK onto the condition **restricts**: a condition somebody asserted
     * is archived, never deleted. The FK onto the registration cascades — an
     * assertion without its act is not evidence of anything.
     *
     * `subject_user_id` and `decided_by_user_id` are Identity's ULIDs, so no
     * FK (ADR-0014). `reason_code` is a bounded code, never free text about a
     * person (T-11, §4.4).
     */
    public function up(): void
    {
        Schema::create('registration_entry_condition_assertions', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Within-module references, so real FKs are fine (ADR-0014).
            $table->foreignUlid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('entry_condition_id')->constrained('registration_entry_conditions')->restrictOnDelete();

            // Identity is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('subject_user_id');

            $table->timestamp('asserted_at');
            $table->text('assertion_text_snapshot');
            $table->string('verification_snapshot', 32);

            $table->string('state', 24);

            $table->ulid('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_via', 16)->nullable();
            $table->string('reason_code', 40)->nullable();

            $table->timestamps();

            $table->unique(['registration_id', 'entry_condition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_entry_condition_assertions');
    }
};
