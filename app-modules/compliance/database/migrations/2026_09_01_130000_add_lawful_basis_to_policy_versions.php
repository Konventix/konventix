<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_versions', function (Blueprint $table): void {
            $table->string('lawful_basis')->default('consent')->after('requires_consent');
        });
    }

    public function down(): void
    {
        Schema::table('policy_versions', function (Blueprint $table): void {
            $table->dropColumn('lawful_basis');
        });
    }
};
