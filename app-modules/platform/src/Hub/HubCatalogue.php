<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

/**
 * Everything the live hub could show, for the operator composing a layout
 * (design §6): every card and link any module registered, gated or not,
 * flattened to the scalars the admin page needs. Event's "Live hub" page
 * reads this — the host module's public service — rather than Platform
 * ever reaching into Event (ADR-0004).
 *
 * Deliberately ungated: `HubRegistry::visible*()` filters by module
 * activation and the *viewer's* capability, but the operator composes for
 * every future member, and a card whose module the brand has not activated
 * yet still belongs in the list — the layout survives the activation.
 * Stateless, so a singleton is safe (`.ai/rules/providers.md`).
 */
final class HubCatalogue
{
    public function __construct(private readonly HubRegistry $registry) {}

    /**
     * @return list<array{key: string, label: string, kind: 'card'|'link', module: string}>
     */
    public function entries(): array
    {
        $sorted = $this->registry->all()->sortBy([
            fn (HubCard|HubLink $a, HubCard|HubLink $b): int => $a->order <=> $b->order,
            fn (HubCard|HubLink $a, HubCard|HubLink $b): int => strcmp($a->label, $b->label),
        ]);

        $entries = [];

        foreach ($sorted as $item) {
            $entries[] = [
                'key' => $item->key,
                'label' => $item->label,
                'kind' => $item instanceof HubCard ? 'card' : 'link',
                'module' => $item->module,
            ];
        }

        return $entries;
    }
}
