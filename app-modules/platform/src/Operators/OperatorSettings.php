<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Data\OperatorSellerData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use RuntimeException;

/**
 * Read-side access to operator-level settlement identity for other modules.
 *
 * Commerce needs the operator's legal identity (invoice seller) and its
 * §19 UStG election (ADR-0030) but may not import the Operator Eloquent
 * model — the cross-module allow-list covers only `Brand`, `Role`,
 * `Permission` and the `Models\Concerns` traits. This service is the
 * sanctioned doorway: plain-scalar Data out, no model leaks.
 *
 * Stateless — safe as a plain container resolution under Octane.
 */
class OperatorSettings
{
    public function sellerForBrand(string $brandId): OperatorSellerData
    {
        $brand = Brand::query()->findOrFail($brandId);

        $operator = $brand->operator;

        if ($operator === null) {
            throw new RuntimeException(
                "Brand [{$brandId}] has no operator; an invoice cannot be issued without a legal seller identity. "
                .'Backfill brands.operator_id (see the seeder) before settling money on this brand.'
            );
        }

        return OperatorSellerData::fromModel($operator);
    }

    public function currencyForBrand(string $brandId): string
    {
        return Brand::query()->findOrFail($brandId)->currency;
    }

    /**
     * Where operator-facing mail goes for this brand — Compliance's deadline
     * escalation is the first caller.
     *
     * One address rather than "everyone holding the capability": a statutory
     * deadline needs an owner, and a mail to fifteen people is a mail nobody
     * answers. Null when the brand has no operator or the operator left the
     * field empty, and the caller then has nowhere to escalate to (which the
     * scheduler logs rather than guessing).
     */
    public function contactEmailForBrand(string $brandId): ?string
    {
        $operator = Brand::query()->find($brandId)?->operator;

        return $operator?->contact_email;
    }

    /**
     * The operator console's own origin for this brand's operator —
     * `scheme://{admin_host}` — for a mail that links a shop operator into
     * the console (ADR-0048 point 3, Commerce's operations notice is the
     * first caller). The scheme is parsed from `config('app.url')` rather
     * than assumed, defaulting to `https` when that config carries none —
     * `admin_host` itself is bare (no scheme, ADR-0029's `ResolveBrand`
     * middleware matches it against the raw request host).
     *
     * Null when the brand has no operator, or the operator has not set an
     * `admin_host` yet — the caller then has nowhere to link to and falls
     * back to a relative path, same as `contactEmailForBrand()`'s "nowhere
     * to escalate to" shape.
     */
    public function adminBaseUrlForBrand(string $brandId): ?string
    {
        $adminHost = Brand::query()->find($brandId)?->operator?->admin_host;

        if ($adminHost === null) {
            return null;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';

        return "{$scheme}://{$adminHost}";
    }

    /**
     * Whether a stranger may create an account right now — Identity's
     * signup gate is the caller (hotfix 2026-09-13).
     *
     * Two switches, and both must be on: the instance cap
     * `platform.signups.enabled` (`SIGNUPS_ENABLED`, config/platform.php)
     * and the operator's own `signups_open`, set on the console's Operator
     * settings page. The environment can therefore close signups on an
     * instance that should never take a stranger's account, and the console
     * cannot re-open them; the console can close them on an instance the
     * environment left open.
     *
     * The operator is the one the request already resolved: the brand's on
     * a brand host, the console's on the admin host. Neither is resolved on
     * a host that matched nothing, and an instance runs as one operator
     * (ADR-0001), so the first row answers there — and an instance with no
     * operator row at all has nobody to sign up to.
     */
    public function signupsOpen(): bool
    {
        if (! config('platform.signups.enabled')) {
            return false;
        }

        $operator = app(BrandContext::class)->current()->operator
            ?? app(OperatorContext::class)->current()
            ?? Operator::query()->first();

        return $operator->signups_open ?? false;
    }

    /**
     * Whether the environment alone has closed signups — the settings page
     * says so next to the switch, so an operator who flips it on and sees
     * nothing change is told why rather than left to wonder.
     */
    public function signupsForcedClosed(): bool
    {
        return ! config('platform.signups.enabled');
    }
}
