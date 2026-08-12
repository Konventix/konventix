<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messaging's recipient read model (SPEC.md "Consumes", TODO.md Next):
     * ULID -> email/display name/locale, projected from Identity's
     * `UserRegistered`/`UserProfileUpdated` events. The primary key IS the
     * user's ULID — deliberately not a generated one, because the row is a
     * projection of a foreign entity, not an entity of its own (ADR-0004).
     * Not brand-scoped: accounts are operator-global (ADR-0016).
     */
    public function up(): void
    {
        Schema::create('messaging_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('email');
            $table->string('display_name');
            $table->string('locale', 12)->default('en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_recipients');
    }
};
