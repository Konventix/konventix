<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content's author read model (ADR-0004): the display name behind an
     * `author_user_id`, projected from Identity's account events. Content may
     * not read Identity's tables, and a byline of raw ULIDs is not a byline.
     *
     * Only the display name is kept. The email is in the event payload too,
     * but nothing on a public article or comment needs it, and a read model
     * that stores contact data it never shows is a GDPR liability with no
     * upside (Compliance's retention sweep would then have to reach in here).
     *
     * Not brand-scoped: accounts are operator-global (ADR-0016), and the same
     * person may comment under two brands. The key is the user's own ULID,
     * assigned from the event rather than generated.
     */
    public function up(): void
    {
        Schema::create('content_authors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_authors');
    }
};
