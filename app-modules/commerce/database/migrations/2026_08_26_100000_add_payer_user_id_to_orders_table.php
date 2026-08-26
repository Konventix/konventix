<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0044: every Commerce root a person owns names its payer. A plain
     * user ULID, no foreign key (ADR-0014): Commerce stores an identifier and
     * never a profile. Nullable only because orders older than this decision
     * have nobody to name — a new root without a payer is a bug, not a case.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('payer_user_id')->nullable()->after('request_key');
            $table->index(['brand_id', 'payer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'payer_user_id']);
            $table->dropColumn('payer_user_id');
        });
    }
};
