<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { show as showEvent } from '@/routes/admin/events';
import { update as updateHubLayout } from '@/routes/admin/events/hub';

/**
 * The live hub's composition for one event (design §6): every card and
 * link Platform's registry knows, in the operator's order, each on or off,
 * and the switch that makes the brand's home page the hub for admitted
 * members.
 *
 * The form is seeded the way Platform merges at render time — the saved
 * layout's order first, then every catalogue entry the layout has not met
 * yet, appended and visible — so a card a newly installed module added
 * shows up at the end without the operator touching anything, and what
 * the operator sees here is what the member gets. A saved key the
 * catalogue no longer knows is dropped from the form the same way the
 * renderer drops it. Reordering is two buttons that swap positions: no
 * drag library is installed, and one is not worth adding for a list of
 * ten rows.
 */
type CatalogueEntry = {
    key: string;
    label: string;
    kind: 'card' | 'link';
    module: string;
};

/** The form's own rows: the generated type is readonly, and the operator edits these. */
type LayoutEntry = { key: string; visible: boolean };

type Props = {
    event: { id: string; name: string };
    catalogue: CatalogueEntry[];
    layout: {
        cards: Konventix.Event.Data.HubLayoutEntryData[];
        hubReplacesHome: boolean;
    };
};

const props = defineProps<Props>();

const byKey = new Map(props.catalogue.map((entry) => [entry.key, entry]));

function seedCards(): LayoutEntry[] {
    const seen = new Set<string>();
    const cards: LayoutEntry[] = [];

    for (const card of props.layout.cards) {
        if (byKey.has(card.key) && !seen.has(card.key)) {
            seen.add(card.key);
            cards.push({ key: card.key, visible: card.visible });
        }
    }

    for (const entry of props.catalogue) {
        if (!seen.has(entry.key)) {
            seen.add(entry.key);
            cards.push({ key: entry.key, visible: true });
        }
    }

    return cards;
}

const form = useForm({
    cards: seedCards(),
    hubReplacesHome: props.layout.hubReplacesHome,
});

useUnsavedChanges(() => form.isDirty);

function entryFor(key: string): CatalogueEntry {
    return byKey.get(key) ?? { key, label: key, kind: 'card', module: '' };
}

function move(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= form.cards.length) {
        return;
    }

    const cards = [...form.cards];
    [cards[index], cards[target]] = [cards[target], cards[index]];
    form.cards = cards;
}

function setVisible(index: number, value: boolean | 'indeterminate'): void {
    form.cards[index].visible = value === true;
}

function submit(): void {
    form.put(updateHubLayout(props.event.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Live hub — ${props.event.name}`" />

    <div class="flex max-w-3xl flex-col gap-6">
        <AdminPageHeader
            :title="`Live hub — ${props.event.name}`"
            description="What admitted members see at the event, and in which order"
        >
            <Link
                :href="showEvent(props.event.id).url"
                class="text-sm text-text-muted hover:text-text"
            >
                Dashboard
            </Link>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Cards and links</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="form.cards.length === 0"
                        class="text-sm text-text-muted"
                    >
                        No module has contributed anything to the hub yet.
                    </p>
                    <ul v-else class="flex flex-col gap-2">
                        <li
                            v-for="(card, index) in form.cards"
                            :key="card.key"
                            class="flex flex-wrap items-center gap-3 rounded-md border border-border p-3"
                        >
                            <Checkbox
                                :id="`hub-card-${card.key}`"
                                :model-value="card.visible"
                                @update:model-value="setVisible(index, $event)"
                            />
                            <Label
                                :for="`hub-card-${card.key}`"
                                class="flex min-w-0 flex-1 flex-wrap items-center gap-2"
                            >
                                <span class="font-medium text-text">
                                    {{ entryFor(card.key).label }}
                                </span>
                                <Badge variant="outline">
                                    {{
                                        entryFor(card.key).kind === 'card'
                                            ? 'Card'
                                            : 'Link'
                                    }}
                                </Badge>
                                <span class="text-xs text-text-muted">
                                    {{ entryFor(card.key).module }}
                                </span>
                            </Label>
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="index === 0"
                                    :aria-label="`Move ${entryFor(card.key).label} up`"
                                    @click="move(index, -1)"
                                >
                                    <ChevronUp class="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="index === form.cards.length - 1"
                                    :aria-label="`Move ${entryFor(card.key).label} down`"
                                    @click="move(index, 1)"
                                >
                                    <ChevronDown class="size-4" />
                                </Button>
                            </div>
                        </li>
                    </ul>
                    <p class="text-xs text-text-muted">
                        Unticked entries stay out of the hub. A card whose
                        module is switched off for the brand is hidden
                        regardless of this list.
                    </p>
                    <InputError :message="form.errors.cards" />
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Home page</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="hub-replaces-home"
                            :model-value="form.hubReplacesHome"
                            aria-describedby="hub-replaces-home-hint"
                            @update:model-value="
                                (value: boolean | 'indeterminate') => {
                                    form.hubReplacesHome = value === true;
                                }
                            "
                        />
                        <Label for="hub-replaces-home">
                            Home page becomes the hub
                        </Label>
                    </div>
                    <p
                        id="hub-replaces-home-hint"
                        class="text-xs text-text-muted"
                    >
                        Applies to signed-in, admitted guests only; the public
                        home page stays cached.
                    </p>
                    <InputError :message="form.errors.hubReplacesHome" />
                </CardContent>
            </Card>

            <div class="flex items-center gap-3 self-start">
                <Button type="submit" :disabled="form.processing">
                    Save changes
                </Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                >
                    Unsaved changes
                </span>
            </div>
        </form>
    </div>
</template>
