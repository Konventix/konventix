<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            // ULID primary key — ADR-0014.
            $table->ulid('id')->primary();

            // Brand-owned — ADR-0010. `BelongsToBrand` stamps this, never a
            // manual where('brand_id', ...) clause.
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // What this order settles — a registration, a resource booking,
            // a shop item — referenced by plain ULID, never an Eloquent
            // relation (ADR-0006, ADR-0014). `settles_type` is a short
            // domain string, not a class-string.
            $table->string('settles_type');
            $table->ulid('settles_id');

            $table->string('status')->default('pending');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_net', 10, 2)->default(0);
            $table->decimal('total_gross', 10, 2)->default(0);

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['settles_type', 'settles_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
