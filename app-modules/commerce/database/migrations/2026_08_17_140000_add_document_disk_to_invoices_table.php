<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which disk an archived invoice document was written to
     * (ADR-0043). Without it, pointing `filesystems.invoices_disk` at object
     * storage would make every already-archived document unreadable — and a
     * GoBD archive must survive a storage migration unaltered (ADR-0031).
     *
     * Null exactly when `document_path` is null: the two are written
     * together, and every reader already guards on the path.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('document_disk')->nullable()->after('document_path');
        });

        // Everything archived so far lives on the original local disk.
        DB::table('invoices')
            ->whereNotNull('document_path')
            ->update(['document_disk' => 'invoices']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('document_disk');
        });
    }
};
