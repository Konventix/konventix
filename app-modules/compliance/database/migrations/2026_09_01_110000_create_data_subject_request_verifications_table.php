<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_request_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Provenance for the mail's layout, like the request's own column.
            // Nullable: the admin host resolves no brand (ADR-0029).
            $table->ulid('filed_on_brand_id')->nullable();

            // The whole reason this table exists, and the reason it is swept: an
            // address typed by a stranger, held on a stranger's say-so until the
            // link is followed (ADR-0050 §6). Not brand-scoped — the request it
            // becomes is not either.
            $table->string('email');
            $table->index('email');

            // DataSubjectRequestType: export|deletion.
            $table->string('type');

            // SHA-256, never the plaintext — same shape as EntranceDevice's bearer
            // token. High entropy makes a slow hash pointless; deterministic
            // hashing is what allows a single indexed lookup.
            $table->string('token_hash')->unique();

            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_request_verifications');
    }
};
