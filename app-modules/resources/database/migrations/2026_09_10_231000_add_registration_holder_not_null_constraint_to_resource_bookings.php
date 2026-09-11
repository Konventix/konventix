<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The previous migration made `holder_id` nullable for every row so
 * erasure could null an operator's own reservation; nothing stopped it
 * being nulled on a *registration*-held booking too, which is not a
 * person id and must never be null (`AssignPlacesToRegistration.php`
 * writes it from `$locked->registration_id`, never a user id — see
 * `ResourcesGdprHandler`'s docblock). This check constraint makes that the
 * schema's rule, not only the handler's intent: a registration-held
 * booking can never have a null holder.
 *
 * `ADD CONSTRAINT ... CHECK (...)` validates every existing row as part of
 * applying the migration — if any row already violated it, this migration
 * would fail outright rather than let a bad state through silently. It was
 * verified clean against the dev database before this migration was
 * written (`ResourceBooking::where('holder_type', 'registration')
 * ->whereNull('holder_id')->count()` = 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE resource_bookings
            ADD CONSTRAINT resource_bookings_registration_holder_not_null
            CHECK (holder_type <> 'registration' OR holder_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE resource_bookings DROP CONSTRAINT resource_bookings_registration_holder_not_null');
    }
};
