<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * The one warning an operator gets when a brand sells without the two texts
 * a purchase path is required to put in front of a payer (#222, #200).
 *
 * The decision behind it is "warn only, no hard refusal at checkout": a
 * running convention must not stop taking money because a text is missing,
 * so the console has to be loud instead. Hence one component rendered in
 * every place an operator manages selling for a brand — the policy console's
 * index and editor, and the settlement console through the
 * `admin.settlement-accounts` slot — rather than a line on one page nobody
 * with the problem happens to open.
 *
 * Not dismissable, on purpose: a notice an operator can close is a notice
 * that comes back as a surprise in a legal letter. `Alert` already carries
 * `role="alert"` (ADR-0055), so a screen reader is told when the page
 * arrives with one.
 *
 * The wording keeps `AGB` and `Widerrufsbelehrung` in German inside an
 * otherwise English console, the way `CheckoutLegalTextRoleData` and
 * `CheckoutLegalTexts::EXEMPTION_NOTICE` already do: those are the names of
 * the documents the statute means, and translating them makes them
 * unfindable.
 */
type Gap = Konventix.Compliance.Data.CheckoutLegalTextGapData;
type Role = Konventix.Compliance.Data.CheckoutLegalTextRoleData;

const props = defineProps<{
    /** Brands the checkout resolves at least one of the two texts for. */
    gaps: Gap[];
    /** What each slug means, so this never restates the convention. */
    roles: Role[];
    /**
     * Where the missing text is created — the policy console. Null on the
     * policy console itself, which opens its own create dialog instead.
     */
    policiesUrl?: string | null;
}>();

function roleName(slug: string): string {
    return props.roles.find((role) => role.slug === slug)?.name ?? slug;
}

/** `?create=` prefills the create dialog with the slug the checkout wants. */
function createUrl(gap: Gap, slug: string): string {
    return `${props.policiesUrl}?create=${slug}&brand=${gap.brandId}`;
}
</script>

<template>
    <Alert v-if="props.gaps.length > 0" variant="destructive">
        <TriangleAlert class="size-4" />
        <AlertTitle>Selling without a Widerrufsbelehrung and AGB</AlertTitle>
        <AlertDescription>
            <p>
                The checkout resolves no legal text for
                {{ props.gaps.length }} brand<span
                    v-if="props.gaps.length !== 1"
                    >s</span
                >. Orders for
                {{ props.gaps.length === 1 ? 'it' : 'them' }} complete right now
                with no consent line written and without the information a
                distance contract has to carry: no confirmed agreement to the
                AGB before the order button (§ 312j BGB) and no
                Widerrufsbelehrung. Publish the missing text under exactly the
                slug named below — a policy under any other slug, such as
                <code>agb</code>, is never found.
            </p>

            <ul class="mt-3 flex flex-col gap-2">
                <li
                    v-for="gap in props.gaps"
                    :key="gap.brandId"
                    class="text-sm"
                >
                    <span class="font-medium">{{ gap.brandName }}</span>
                    — missing
                    <span v-for="(slug, index) in gap.missingSlugs" :key="slug">
                        <span v-if="index > 0">, </span>
                        {{ roleName(slug) }}
                        <span class="text-text-muted"
                            >(slug <code>{{ slug }}</code
                            >)</span
                        >
                    </span>

                    <span
                        v-if="props.policiesUrl"
                        class="mt-1 flex flex-wrap gap-3"
                    >
                        <Link
                            v-for="slug in gap.missingSlugs"
                            :key="slug"
                            :href="createUrl(gap, slug)"
                            class="font-medium underline underline-offset-4"
                        >
                            Create {{ roleName(slug) }} for
                            {{ gap.brandName }}
                        </Link>
                    </span>

                    <slot name="actions" :gap="gap" />
                </li>
            </ul>
        </AlertDescription>
    </Alert>
</template>
