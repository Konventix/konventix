<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Konventix\Admission\Models\EntranceSigningKey;

/**
 * Gives a brand an entrance signing key if — and only if — it has none that
 * can still sign (ADR-0033, #287).
 *
 * `RotateEntranceSigningKey` is the deliberate act: it always mints and
 * always retires whatever came before. This is the lifecycle guard in front
 * of it, and the difference matters because everything that calls it runs
 * more than once: the `BrandCreated` listener on a replay, the deploy-path
 * backfill on every release. Calling `rotate()` from either would retire a
 * brand's working key on every deploy for no reason — and every rotation
 * costs the operator nothing visible only because retirement never expires
 * a key.
 *
 * Returns the key it minted, or null when the brand already had one — which
 * is what lets a caller report how many brands it actually touched.
 *
 * Deliberately no lock. Two processes racing here both see no active key and
 * both rotate; the second retires the first's key on its way in, so the end
 * state is exactly one key that can sign, which is the invariant. A lock
 * would buy a spare keypair row, nothing more.
 */
final class EnsureEntranceSigningKey
{
    public function __construct(private readonly RotateEntranceSigningKey $rotate) {}

    public function handle(string $brandId): ?EntranceSigningKey
    {
        if ($this->hasSigningKey($brandId)) {
            return null;
        }

        return $this->rotate->handle($brandId);
    }

    /**
     * withoutBrandScope + explicit brand (ADR-0010): this runs from console
     * commands and from a listener that may be handling another brand's
     * event, neither of which has the right ambient brand.
     */
    public function hasSigningKey(string $brandId): bool
    {
        return EntranceSigningKey::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('retired_at')
            ->whereNotNull('activated_at')
            ->where('activated_at', '<=', now())
            ->where('expires_at', '>', now())
            ->exists();
    }
}
