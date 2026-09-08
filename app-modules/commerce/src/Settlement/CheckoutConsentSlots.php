<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Platform\Slots\SlotRegistry;

/**
 * The whole of what Commerce knows about the consent control its checkouts
 * render — read in one place instead of once per controller (review 6
 * finding 9).
 *
 * Compliance contributes to the `checkout.consent` slot (ADR-0003
 * enforcement point 3) because Commerce may not read a policy row
 * (ADR-0004). Two facts come back, and only two:
 *
 * - `required`: the payer must confirm the contribution before the order is
 *   placed (GitLab #2);
 * - `outstandingUrl`: where the payer has to settle an outstanding
 *   *contract* agreement before this purchase may proceed at all, or null
 *   (GitLab #205).
 *
 * A URL and a boolean. Commerce never learns that a policy exists, which is
 * exactly what keeps the module boundary honest — and having one reader
 * means the cart checkout and the pay pages cannot drift apart on what the
 * contribution meant.
 *
 * `SlotRegistry` is a boot-time singleton holding registrations only, so
 * constructor injection is safe here (.ai/rules/providers.md); the props
 * closures it holds resolve their own scoped bindings per call.
 */
final class CheckoutConsentSlots
{
    public function __construct(private readonly SlotRegistry $slots) {}

    /**
     * The contributions as an Inertia page receives them.
     *
     * @return list<array{module: string, component: string, props: array<string, mixed>}>
     */
    public function contributions(): array
    {
        return array_values($this->slots->for('checkout.consent')
            ->map(fn (array $entry): array => [
                'module' => $entry['module'],
                'component' => $entry['component'],
                'props' => $entry['props'],
            ])
            ->all());
    }

    /**
     * Whether a contribution says the payer must confirm it before the
     * order is placed.
     *
     * @param  list<array{module: string, component: string, props: array<string, mixed>}>  $contributions
     */
    public function declarationRequired(array $contributions): bool
    {
        foreach ($contributions as $contribution) {
            if (($contribution['props']['required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a contribution says this payer must settle an outstanding
     * agreement first, or null when none says so.
     *
     * @param  list<array{module: string, component: string, props: array<string, mixed>}>  $contributions
     */
    public function outstandingUrl(array $contributions): ?string
    {
        foreach ($contributions as $contribution) {
            $url = $contribution['props']['outstandingUrl'] ?? null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
