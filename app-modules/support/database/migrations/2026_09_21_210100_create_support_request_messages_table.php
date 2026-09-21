<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The thread after the original report (design §4.2). The reporter's own
     * first text stays on `support_requests.message`: it is the report, not
     * a reply to one, and keeping it there is what lets the GDPR handler
     * scrub "what this person wrote" without walking two tables for the
     * common case.
     *
     * `visibility` is the whole privacy mechanism of the thread: an
     * `internal` note is staff-only forever, an `external` message is what
     * the reporter reads in "My support requests" or through their signed
     * link. There is no third state and no per-message recipient list — the
     * SPEC's scope ceiling says this is a report channel with a thread, not
     * a helpdesk.
     *
     * `author_user_id` is null for the guest reporter, who is identified by
     * the signed link rather than by an account (ADR-0014: Identity is a
     * different module, so a plain ULID and no FK).
     *
     * Only `created_at`: a support message is never edited. The console
     * writes a new one instead, which is also what the audit trail records.
     */
    public function up(): void
    {
        Schema::create('support_request_messages', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Same module, so a real FK: a purged request takes its thread
            // with it, which is how `support:purge-requests` stays one
            // delete (D-SUP-11).
            $table->foreignUlid('support_request_id')->constrained('support_requests')->cascadeOnDelete();

            // Null = the guest reporter behind the signed link.
            $table->ulid('author_user_id')->nullable();

            $table->string('visibility', 16);

            $table->text('body');

            $table->timestamp('created_at')->nullable();

            // The thread, in order, is the only read there is.
            $table->index(['support_request_id', 'created_at']);
            // The GDPR handler scrubs the person's own messages.
            $table->index('author_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_request_messages');
    }
};
