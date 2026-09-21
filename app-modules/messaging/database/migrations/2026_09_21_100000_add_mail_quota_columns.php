<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mail quota (bugfix round 2026-09-21, Task 14). Two nullable
     * per-brand overrides on the settings row — null means "the configured
     * default" (`config('messaging.mail_quota')`), so an instance-wide
     * change in the environment reaches every brand that never set its
     * own — and a `hold_reason` on the log row so the flush sweep can tell
     * a quota hold (sent as itself, re-checked against the quota, never
     * coalesced) from a digest hold (coalesced per recipient). Null keeps
     * meaning the digest/quiet-hours hold every existing row is.
     */
    public function up(): void
    {
        Schema::table('messaging_brand_settings', function (Blueprint $table): void {
            $table->unsignedInteger('mail_quota_per_hour')->nullable()->after('timezone');
            $table->unsignedInteger('mail_quota_per_day')->nullable()->after('mail_quota_per_hour');
        });

        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->string('hold_reason', 32)->nullable()->after('hold_until');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->dropColumn('hold_reason');
        });

        Schema::table('messaging_brand_settings', function (Blueprint $table): void {
            $table->dropColumn(['mail_quota_per_hour', 'mail_quota_per_day']);
        });
    }
};
