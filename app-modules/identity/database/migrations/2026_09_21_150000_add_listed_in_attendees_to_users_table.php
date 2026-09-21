<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `listed_in_attendees` (ADR-0064 §1): the person's opt-in to be named on
 * the attendee list of an event they are confirmed for. Defaults every
 * existing row to false — disclosure is by choice, so nobody is listed
 * for a feature that did not exist when they registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('listed_in_attendees')->default(false)->after('profile_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('listed_in_attendees');
        });
    }
};
