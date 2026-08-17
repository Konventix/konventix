<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Digest/quiet-hours bookkeeping (ADR-0041 point 5).
     *
     * `recipient_locale` rides on the log row because a held envelope is
     * reconstructed from it at flush time — without the locale the flush
     * would re-render a held message in the platform default rather than
     * what the recipient would have received immediately.
     *
     * `digest_message_id` records where a coalesced row's content actually
     * went: the digest message that carried it. Support's "did they get the
     * mail" then has a precise answer for digested sends too.
     *
     * `messaging_brand_settings.timezone` is the quiet-hours fallback for
     * recipients whose read model carries none — a Messaging delivery
     * concern, so it lives on Messaging's own per-brand settings row
     * (ADR-0028), not on Platform's Brand.
     */
    public function up(): void
    {
        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->string('recipient_locale', 12)->nullable()->after('recipient_email');
            $table->ulid('digest_message_id')->nullable()->after('hold_until');
        });

        Schema::table('messaging_brand_settings', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_brand_settings', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });

        Schema::table('messaging_messages', function (Blueprint $table): void {
            $table->dropColumn(['recipient_locale', 'digest_message_id']);
        });
    }
};
