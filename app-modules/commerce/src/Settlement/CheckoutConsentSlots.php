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
 * Since ADR-0066 the contribution is asked *in context* (D-CPL-06): the
 * union of the lines' opaque `binding_context` bags, plus the two facts
 * Commerce owns — the provider the payer chose in step one and the order id.
 * Commerce interprets no key of any bag; it hands them over exactly as it
 * received them, and Compliance is the only module that knows what any of
 * them mean. A line with no bag — every line placed before this feature,
 * since there was no backfill — contributes nothing, and the resolution then
 * returns the unbound policies only, which is what it always returned.
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
     * `$context` is what the rendering surface knows about this purchase —
     * `bindingContext`, `settlementProvider` and `orderId`. An empty context
     * is the brand-wide question, which is what a page with no provider
     * chosen yet asks.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{module: string, component: string, props: array<string, mixed>}>
     */
    public function contributions(array $context = []): array
    {
        return array_values($this->slots->for('checkout.consent', $context)
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

    /**
     * The union of several lines' opaque bags (ADR-0066 D-CPL-06).
     *
     * A cart may hold two registrations, so the types and the conditions are
     * unioned: the resolution is OR within a kind, and a text bound to either
     * type applies to the order that buys both — over-asking rather than
     * under-asking (T-10).
     *
     * `event_id` and `registration_id` are single-valued in the resolver's
     * context, so the first bag that names one wins. A cart spanning two
     * events therefore resolves the first event's bindings only; that is a
     * known narrowing, recorded in `TODO.md`, and the alternative — a list
     * of events the resolver has no shape for — is a change to Compliance's
     * contract rather than to this method.
     *
     * Nothing here reads a *meaning*: the keys are copied, merged and
     * de-duplicated as opaque strings.
     *
     * @param  list<array<string, mixed>|null>  $bags
     * @return array<string, mixed>
     */
    public static function unionOfBags(array $bags): array
    {
        $union = [];
        $types = [];
        $conditions = [];

        foreach ($bags as $bag) {
            if (! is_array($bag)) {
                continue;
            }

            foreach (['event_id', 'registration_id'] as $single) {
                if (! isset($union[$single]) && is_string($bag[$single] ?? null)) {
                    $union[$single] = $bag[$single];
                }
            }

            $types = [...$types, ...self::stringList($bag['registration_type_ids'] ?? null)];
            $conditions = [...$conditions, ...self::stringList($bag['entry_condition_ids'] ?? null)];
        }

        if ($types !== []) {
            $union['registration_type_ids'] = array_values(array_unique($types));
        }

        if ($conditions !== []) {
            $union['entry_condition_ids'] = array_values(array_unique($conditions));
        }

        return $union;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
