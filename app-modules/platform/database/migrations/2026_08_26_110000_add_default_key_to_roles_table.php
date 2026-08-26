<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0045: a role materialized from a module's default set records its
     * origin (`default_key`, e.g. `registration.desk`) and whether the
     * operator has since edited it (`customized_at`). Sync rewrites only the
     * rows where the second is null; `updated_at` could not serve, because it
     * bumps on a no-op save and not on a grant change.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('default_key')->nullable()->after('guard_name');
            $table->timestamp('customized_at')->nullable()->after('default_key');
            $table->unique(['brand_id', 'default_key']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'default_key']);
            $table->dropColumn(['default_key', 'customized_at']);
        });
    }
};
