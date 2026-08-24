<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The operator's footer entries, rendered on every brand-facing page.
     *
     * Operator-owned rather than brand-owned: these are the operator's legal
     * and policy destinations, and one legal entity answers for all its
     * brands (ADR-0001). Deliberately NOT using BelongsToBrand — a
     * brand-scoped table would make the imprint something a brand could
     * forget to configure. A nullable `brand_id` can be added later if a
     * brand ever needs entries of its own; nothing here would have to change
     * shape for that.
     */
    public function up(): void
    {
        Schema::create('footer_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // 'url' navigates, 'dialog' opens something in the page — see
            // FooterLinkKind. Exactly one of the two columns below carries
            // the destination, enforced in the Data object's rules.
            $table->string('kind');
            $table->string('url', 2048)->nullable();
            $table->string('dialog_key')->nullable();

            $table->boolean('opens_in_new_tab')->default(false);
            $table->unsignedSmallInteger('position');

            $table->timestamps();

            $table->index(['operator_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footer_links');
    }
};
