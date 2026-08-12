<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('brand_id')->index();

            // Same non-account-tolerant shape as consents.subject_id — see
            // that migration's comment and decision-log Round 5 D-REG-08.
            $table->ulid('subject_id')->index();

            // DataSubjectRequestType: export|deletion|rectification.
            $table->string('type');

            // DataSubjectRequestState: received|in_progress|fulfilled|refused.
            $table->string('state')->default('received');

            // DeletionMode: anonymize|erase. Only meaningful for type=deletion.
            $table->string('mode')->nullable();

            // Statutory deadline, computed on create as now() + 30 days by
            // OpenSubjectRequest.
            $table->timestamp('deadline_at');

            $table->timestamps();
        });

        Schema::create('data_subject_request_responses', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Within-module FK — both tables live in Compliance.
            $table->foreignUlid('data_subject_request_id')->constrained()->cascadeOnDelete();

            // The responding module's kebab-case name (GdprHandler::module()).
            $table->string('module');

            // ModuleResponseState: pending|completed|failed.
            $table->string('state')->default('pending');

            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // One row per (request, module) — this is what makes
            // RecordModuleResponse an idempotent upsert instead of an
            // append-only log.
            $table->unique(['data_subject_request_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_request_responses');
        Schema::dropIfExists('data_subject_requests');
    }
};
