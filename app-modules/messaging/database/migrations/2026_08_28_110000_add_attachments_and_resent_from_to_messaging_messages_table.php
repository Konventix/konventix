<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two additions the delivery-log screen and Resend need (ADR-0048
     * decision 6). `attachments` mirrors what actually rode on the
     * envelope — a list of `{disk, path, as, mime}`, the same shape as
     * `AttachmentData` — written by `SendMessage::log()` from the
     * request's attachment collection, so a resend can re-attach the same
     * files when they are still on disk. `resent_from_id` links a
     * resend's fresh log row back to the record an operator resent it
     * from; no FK constraint, same reasoning ADR-0014 gives cross-module
     * foreign columns — this one merely points at another row of the same
     * table, and a message the sweep purges must not be blocked by one.
     */
    public function up(): void
    {
        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->jsonb('attachments')->nullable()->after('payload');
            $table->ulid('resent_from_id')->nullable()->after('digest_message_id');
            $table->index('resent_from_id');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->dropIndex(['resent_from_id']);
            $table->dropColumn(['attachments', 'resent_from_id']);
        });
    }
};
