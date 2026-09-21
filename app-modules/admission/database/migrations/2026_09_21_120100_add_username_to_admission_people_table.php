<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The handle beside the display name (ADR-0065): the ticket page, the
     * ticket list, the manager's rows and the claim page name a holder or
     * an inviter as `@username`; the display name stays for the ticket
     * PDF (a document) and the console. Nullable, because a row projected
     * before this column existed carries none until
     * `identity:replay-profiles` re-emits the profile.
     */
    public function up(): void
    {
        Schema::table('admission_people', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('admission_people', function (Blueprint $table): void {
            $table->dropColumn('username');
        });
    }
};
