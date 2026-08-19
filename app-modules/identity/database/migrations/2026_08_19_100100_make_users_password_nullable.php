<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account created through Steam has no password, and storing a random
     * unusable hash instead would be a lie the code would then have to keep:
     * every "can this person log in by mail?" question would have to know
     * which hashes were real. Null says it once.
     *
     * `AuthenticateUser` refuses the password path for a null-password account,
     * and the person can adopt a password at any time through the existing
     * reset-password flow — which is how a Steam-only account gains email
     * login without a second mechanism.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
