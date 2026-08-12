<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Cross-module reference to Platform's brands table: plain ulid,
            // no FK constraint (ADR-0014). BelongsToBrand stamps and scopes
            // it — never a hand-written where('brand_id', ...) (ADR-0010).
            $table->ulid('brand_id')->index();

            $table->string('slug');
            $table->string('name');
            $table->timestamps();

            // Slugs are unique per brand, not globally — two brands may both
            // publish a "terms" policy.
            $table->unique(['brand_id', 'slug']);
        });

        Schema::create('policy_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('brand_id')->index();

            // Within-module FK — Policy and PolicyVersion both live in
            // Compliance, so a real constraint is fine here (ADR-0014).
            $table->foreignUlid('policy_id')->constrained()->cascadeOnDelete();

            $table->string('version');
            $table->text('content');
            $table->boolean('requires_consent')->default(true);

            // PolicyVersionState: draft|published|superseded. Publication
            // only ever happens through PublishPolicyVersion, which is what
            // guarantees at most one 'published' row per policy — see that
            // Action's docblock.
            $table->string('state')->default('draft');

            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // A given version label is unique within its policy.
            $table->unique(['policy_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
    }
};
