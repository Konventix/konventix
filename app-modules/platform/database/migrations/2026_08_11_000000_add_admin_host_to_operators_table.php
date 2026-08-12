<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The operator's admin console host (ADR-0029). Data like brand domains:
     * host lookup runs on every request, so it is unique and indexed.
     */
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('admin_host')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('admin_host');
        });
    }
};
