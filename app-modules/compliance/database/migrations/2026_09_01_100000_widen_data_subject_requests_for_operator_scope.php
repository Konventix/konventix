<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            // Provenance, never a scope (ADR-0050 §1): which brand's host the
            // request arrived on, so the acknowledgement renders in that
            // brand's layout. Nullable because a request filed on the admin
            // host has no brand at all (ADR-0029).
            $table->ulid('filed_on_brand_id')->nullable()->after('id');

            // A subject with no account (ADR-0050 §3).
            $table->string('subject_email')->nullable()->after('subject_id');
        });

        // Expand/contract (ADR-0049 §9): populate the new column while the
        // old one is still read, so a pre-upgrade pod and a post-upgrade pod
        // both work. `brand_id` is dropped a release later.
        DB::statement('UPDATE data_subject_requests SET filed_on_brand_id = brand_id');

        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->ulid('subject_id')->nullable()->change();

            // `DataSubjectRequest` no longer uses BelongsToBrand, so nothing
            // stamps this column on create any more — only filed_on_brand_id
            // is written going forward (ADR-0050 §1). It can no longer stay
            // NOT NULL or every new row's insert would fail; a pre-upgrade
            // pod still reading it simply sees no brand for a row created
            // after the upgrade, same as the admin-host "—" it already
            // renders for a missing one.
            $table->ulid('brand_id')->nullable()->change();

            $table->index('subject_email');
        });

        // Exactly one of the two identifies the subject. A check constraint
        // rather than model validation: this is an invariant of the row, and
        // a background job writing directly must not be able to break it.
        DB::statement(
            'ALTER TABLE data_subject_requests ADD CONSTRAINT data_subject_requests_subject_identified'
            .' CHECK ((subject_id IS NULL) <> (subject_email IS NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE data_subject_requests DROP CONSTRAINT data_subject_requests_subject_identified');

        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropIndex(['subject_email']);
            $table->dropColumn(['filed_on_brand_id', 'subject_email']);
        });
    }
};
