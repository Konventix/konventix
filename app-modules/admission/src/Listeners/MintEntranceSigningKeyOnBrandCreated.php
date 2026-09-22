<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\EnsureEntranceSigningKey;
use Konventix\Platform\Events\BrandCreated;

/**
 * A brand can issue tickets from its first minute (#287).
 *
 * Nothing used to mint an entrance signing key, so the first
 * `GET /my/registrations/{id}/ticket` of every brand answered 500 —
 * `IssueAdmissionToken` refuses to sign without one (ADR-0033) — and the
 * advice in that exception named a command that did not exist. This is the
 * lifecycle half of the fix; `admission:rotate-signing-key --all` is the
 * backfill half, for brands created before it and for every path that
 * builds a `Brand` row without going through `CreateBrand`.
 *
 * The payload carries the brand's ULID and nothing else this needs:
 * Admission never imports Platform's `Brand` model (ADR-0004), and the key
 * row's `brand_id` is written explicitly rather than from ambient context
 * (ADR-0010) — a brand was just created, it is not the brand this request
 * resolved.
 *
 * Idempotent through `EnsureEntranceSigningKey`: a replayed event finds the
 * key already there and writes nothing, rather than retiring a working key.
 *
 * The Action is resolved inside `handle()`, not injected: the dispatcher is
 * a boot-time singleton that builds listeners with the worker's base
 * container, so anything scoped a dependency of this reaches for later
 * would be the wrong instance under Octane (`.ai/rules/listeners.md`).
 */
final class MintEntranceSigningKeyOnBrandCreated
{
    public function handle(BrandCreated $event): void
    {
        app(EnsureEntranceSigningKey::class)->handle($event->brandId);
    }
}
