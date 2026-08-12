<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which amendment issued this admission, if any (ADR-0036). Null means
     * the original confirmation issued it.
     *
     * This is what lets issuance stay idempotent once a registration can
     * receive grants more than once: the guard asks "have I issued for this
     * amendment?" instead of "does this registration have any admissions?",
     * which would swallow every delta.
     *
     * Registration owns the amendment, so this is a plain ULID with no FK
     * constraint (ADR-0014).
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->ulid('amendment_id')->nullable()->after('registration_id');

            $table->index(['registration_id', 'amendment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropIndex(['registration_id', 'amendment_id']);
            $table->dropColumn('amendment_id');
        });
    }
};
