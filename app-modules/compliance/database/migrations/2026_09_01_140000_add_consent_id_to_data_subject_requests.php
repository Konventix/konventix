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
            // Which Consent a ConsentWithdrawal request is about (ADR-0050
            // §8, controller ruling 3). Within-module FK — both tables are
            // Compliance's, so a `foreignUlid` constrained to `consents` is
            // allowed here (.ai/rules — cross-module FKs are the thing that
            // is not). Nullable: every other request type leaves it unset.
            //
            // nullOnDelete rather than cascadeOnDelete or restrict: a
            // deleted Consent must not strand this row in a state nothing
            // can resolve. Cascading would destroy the DataSubjectRequest —
            // and with it the operator's own record of having received and
            // (potentially) refused a request, which is itself
            // audit-relevant. Restricting would block deleting the Consent
            // for as long as the request stays open. Nulling out the
            // pointer keeps the request exactly as reachable and answerable
            // as before; only the (currently nonexistent) path that deletes
            // a Consent outright loses the link.
            $table->foreignUlid('consent_id')->nullable()->after('subject_email')->constrained('consents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('consent_id');
        });
    }
};
