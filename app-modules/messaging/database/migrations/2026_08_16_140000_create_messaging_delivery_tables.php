<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery log, the suppression list and the preference rows
     * (ADR-0041). One log row per accepted envelope (per channel); statuses
     * queued → sent | failed | suppressed | held. Bodies are stored only
     * for template keys flagged logBodies (D-MSG-07 hybrid). Shaped for the
     * D-CPL-01 twelve-month purge from day one.
     */
    public function up(): void
    {
        Schema::create('messaging_messages', function (Blueprint $table): void {
            // The envelope's messageId — assigned at accept time, not
            // generated here, so log row and events share one identifier.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010/ADR-0028.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->ulid('recipient_id')->nullable();
            $table->string('recipient_email');

            $table->string('channel', 16);
            $table->string('template_key');
            $table->string('topic_key')->nullable();

            // queued | held | sent | failed | suppressed — MessageStatus.
            $table->string('status', 16);

            // The typed payload (ADR-0009) — always kept; support can see
            // what was said without a body archive.
            $table->jsonb('payload');

            // Only for logBodies-flagged templates (D-MSG-07 hybrid).
            $table->text('rendered_subject')->nullable();
            $table->longText('rendered_body')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->string('provider_message_id')->nullable();

            $table->boolean('urgent')->default(false);

            // Digest/quiet-hours hold (ADR-0041): due when hold_until passes.
            $table->timestamp('hold_until')->nullable();

            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            // Log view + retention purge walk per brand by age.
            $table->index(['brand_id', 'created_at']);
            // The digest sweep reads exactly this.
            $table->index(['status', 'hold_until']);
            $table->index(['recipient_id', 'channel']);
        });

        Schema::create('messaging_suppressions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Per brand, never global (ADR-0028: reputation is per sending
            // domain).
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 16);

            // Mail suppresses the address (an invoice contact has no
            // account); push/in-app suppress the recipient ULID. Exactly one
            // of the two is set.
            $table->string('email')->nullable();
            $table->ulid('recipient_id')->nullable();

            $table->string('reason_code', 64);
            $table->timestamp('suppressed_at');
            $table->timestamp('lifted_at')->nullable();

            $table->timestamps();
        });

        // One LIVE suppression per (brand, channel, address|recipient);
        // lifted rows stay as history.
        DB::statement(
            'CREATE UNIQUE INDEX messaging_suppressions_live_email_unique'
            .' ON messaging_suppressions (brand_id, channel, email)'
            .' WHERE lifted_at IS NULL AND email IS NOT NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX messaging_suppressions_live_recipient_unique'
            .' ON messaging_suppressions (brand_id, channel, recipient_id)'
            .' WHERE lifted_at IS NULL AND recipient_id IS NOT NULL',
        );

        Schema::create('messaging_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The recipient's own ULID — recipients are operator-global
            // (ADR-0016), so preferences are too.
            $table->ulid('recipient_id');

            $table->string('topic_key');
            $table->string('channel', 16);
            $table->boolean('enabled');

            $table->timestamps();

            $table->unique(['recipient_id', 'topic_key', 'channel']);
        });

        Schema::table('messaging_recipients', function (Blueprint $table): void {
            // Quiet hours run in the recipient's timezone (ADR-0041); null
            // falls back to the brand's. Identity's contract events do not
            // carry a timezone yet — the column waits for that payload.
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_recipients', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
        Schema::dropIfExists('messaging_preferences');
        Schema::dropIfExists('messaging_suppressions');
        Schema::dropIfExists('messaging_messages');
    }
};
