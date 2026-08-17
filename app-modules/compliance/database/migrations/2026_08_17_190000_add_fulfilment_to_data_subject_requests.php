<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a subject request looks like once it has been *carried out*: the
 * export archive it produced, when that archive stops being available, when
 * the request closed, and — for a refusal — the legal ground.
 *
 * The archive columns mirror Commerce's invoice documents (ADR-0043): the
 * disk is stored next to the path, so an archive stays readable after the
 * deployment points new exports somewhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->string('archive_path')->nullable();
            $table->string('archive_disk')->nullable();

            // Deliberately short: the archive is a second copy of a person's
            // data that exists only to be handed over once, so it expires and
            // is deleted rather than kept "in case".
            $table->timestamp('archive_expires_at')->nullable();

            $table->timestamp('closed_at')->nullable();

            // SubjectRequestRefused's legal ground (SPEC "Public contract").
            $table->string('reason_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'archive_path',
                'archive_disk',
                'archive_expires_at',
                'closed_at',
                'reason_code',
            ]);
        });
    }
};
