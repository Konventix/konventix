<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-pinned operator-console shortcuts (ADR-0029), ported concept from
     * LanCore's sidebar favorites. `user_id` is a plain ULID — cross-module
     * reference to Identity's User, no FK constraint (ADR-0014). Not
     * brand-owned: the admin console spans brands, there is nothing to scope
     * to.
     */
    public function up(): void
    {
        Schema::create('admin_favorites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->index();
            $table->string('label');
            $table->string('url');
            $table->timestamps();

            $table->unique(['user_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_favorites');
    }
};
