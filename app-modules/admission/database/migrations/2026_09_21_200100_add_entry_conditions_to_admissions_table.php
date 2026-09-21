<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the door will have to ask this holder for (ADR-0066 §6.4,
     * D-ADM-06): the list `RegistrationConfirmed` carries beside its grants,
     * stamped on every admission the confirmation issues.
     *
     * Opaque scalars, deliberately, and not a projected table. Admission
     * reads no Registration row and learns of no assertion — it is handed
     * four fields per condition (`key`, `label`, `verification`,
     * `document_hint`) and carries them to the entrance payload and the
     * ticket unchanged (ADR-0002, ADR-0004, `.ai/rules/tickets.md`). A
     * condition reworded later does not rewrite a ticket already in
     * somebody's pocket, which is the same reason the assertion snapshots
     * its own text.
     *
     * Nullable, with no backfill: every admission issued before this
     * migration carries null and the door asks for nothing, exactly as it
     * does today.
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->json('entry_conditions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropColumn('entry_conditions');
        });
    }
};
