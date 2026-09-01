<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            // A second single-use, hashed link for a subject who cannot sign
            // in to use the authenticated download (ADR-0050 §9) — same
            // shape as DataSubjectRequestVerification's token_hash. Null
            // until issued, and cleared again the moment it is consumed, so
            // a forwarded mail is already spent.
            $table->string('archive_token_hash')->nullable()->unique()->after('archive_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropColumn('archive_token_hash');
        });
    }
};
