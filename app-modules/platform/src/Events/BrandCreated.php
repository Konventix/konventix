<?php

declare(strict_types=1);

namespace Konventix\Platform\Events;

/**
 * A brand was created under an operator (SPEC.md "Public contract").
 * Scalars only (ADR-0004) — a consumer that needs more reads its own read
 * model, never `Konventix\Platform\Models\Brand`.
 *
 * Dispatched by `Actions\CreateBrand` *after* its transaction commits, the
 * same moment `MaterializeDefaultRoles` runs: until the row is committed
 * the fact this event announces has not happened, and a consumer that
 * writes brand-owned rows of its own (Admission's entrance signing key)
 * must not have them rolled back underneath it.
 *
 * Paths that build a `Brand` row directly rather than through the Action —
 * `konventix:install`, the dev and demo seeders, `Brand::factory()` — do
 * not raise it, because the host seeders run under `WithoutModelEvents`
 * and a model hook would be muted there anyway (`BrandSeeder` says so about
 * the Reverb credentials). Whatever a consumer needs for those brands it
 * backfills on the deploy path instead; Admission's is
 * `admission:rotate-signing-key --all`, which `konventix:migrate` and
 * `konventix:install` both run.
 */
final readonly class BrandCreated
{
    public function __construct(
        public string $brandId,
        public string $operatorId,
        public string $slug,
        public string $name,
    ) {}
}
