<?php

declare(strict_types=1);

namespace Konventix\Identity\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;

/**
 * Establishes the user↔brand relationship the first time an account touches a
 * brand (ADR-0016): registration and login both count as "seen". Idempotent —
 * the (user, brand) pair is unique and only the first contact writes.
 *
 * With no brand in context (console, tests without a request) it does nothing;
 * membership is a statement about a brand interaction, and without a brand
 * there was none.
 */
class RecordBrandMembership
{
    public function __construct(private readonly BrandContext $context) {}

    public function handle(Login|Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $this->context->hasBrand()) {
            return;
        }

        // The brand scope constrains the lookup to the current brand and the
        // BelongsToBrand creating hook stamps brand_id — no manual brand
        // handling, per ADR-0010.
        BrandMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['first_seen_at' => now()],
        );
    }
}
