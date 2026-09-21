<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The attendee list's two facts about a registrant (ADR-0064 §3, §6): the
 * handle a name on the list is, and whether the person opted in to be
 * named at all. Both projected from `UserProfileUpdated` by
 * `Listeners\ProjectRegistrant`; `username` nullable because a row
 * projected before this deploy has not been told one yet — the replay
 * (`identity:replay-profiles`) fills it, and until then the person is
 * counted and not named, the safe direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_registrants', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('email');
            $table->boolean('listed')->default(false)->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('registration_registrants', function (Blueprint $table): void {
            $table->dropColumn(['username', 'listed']);
        });
    }
};
