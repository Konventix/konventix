<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotas', function (Blueprint $table): void {
            // The category-to-quota link (design §12b, #282): a plain ULID,
            // no FK constraint — Resources owns ResourceType, not this
            // module, and cross-module columns never take one (ADR-0014,
            // same shape as GrantData::$resourceTypeIds). Nullable: most
            // quotas name no category at all.
            $table->ulid('resource_type_id')->nullable()->after('name');
            $table->index('resource_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotas', function (Blueprint $table): void {
            $table->dropIndex(['resource_type_id']);
            $table->dropColumn('resource_type_id');
        });
    }
};
