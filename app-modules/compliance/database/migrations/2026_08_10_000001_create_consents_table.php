<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('brand_id')->index();

            // Holds a user's ULID today — Phase 1 requires an account
            // (decision-log Round 5 D-REG-08) — but this column must not
            // assume a `users` table: guest registration will issue its own
            // non-account subject identity later and this has to keep
            // working unchanged. No FK, by design (ADR-0004/0014).
            $table->ulid('subject_id')->index();

            // Within-module FK — Consent and PolicyVersion both live in
            // Compliance.
            $table->foreignUlid('policy_version_id')->constrained('policy_versions')->cascadeOnDelete();

            $table->timestamp('granted_at');
            $table->string('capture_context');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['subject_id', 'policy_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
