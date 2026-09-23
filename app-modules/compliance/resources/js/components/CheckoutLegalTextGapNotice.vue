<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';

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
 * that comes back as a surprise in a legal letter.
 *
 * A landmark, not a live region (branch review finding 20). It used
 * `ui/alert`, whose wrapper hardcodes `role="alert"` — an *assertive*,
 * atomic region: every navigation to the policy index re-read a 90-word
 * § 312j paragraph over whatever was being read, and the `#actions`
 * buttons sat inside it, which the ARIA APG names as an anti-pattern.
 * A standing page warning is a `role="region"` labelled by a real heading
 * instead, so a screen-reader user browsing by heading lands on the one
 * warning this exists to make loud. The colours are the same `destructive`
 * tokens `ui/alert` uses, written out rather than imported, because the
 * role is the only thing that had to change.
 *
 * The wording keeps `AGB` and `Widerrufsbelehrung` in German inside the
 * English source text (ADR-0068), the way `CheckoutLegalTextRoleData` and
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
    <section
        v-if="props.gaps.length > 0"
        role="region"
        aria-labelledby="checkout-legal-text-gap"
        class="w-full rounded-lg border border-border bg-card px-4 py-3 text-sm text-destructive"
    >
        <h2
            id="checkout-legal-text-gap"
            class="flex items-start gap-3 font-medium"
        >
            <TriangleAlert
                class="size-4 shrink-0 translate-y-0.5"
                aria-hidden="true"
            />
            {{ $t('Selling without a Widerrufsbelehrung and AGB') }}
        </h2>

        <div class="mt-1 pl-7 text-destructive/90">
            <p>
                {{
                    $tChoice(
                        '{1} The checkout resolves no legal text for :count brand. Orders for it complete right now with no consent line written and without the information a distance contract has to carry: no confirmed agreement to the AGB before the order button (§ 312j BGB) and no Widerrufsbelehrung.|[2,*] The checkout resolves no legal text for :count brands. Orders for them complete right now with no consent line written and without the information a distance contract has to carry: no confirmed agreement to the AGB before the order button (§ 312j BGB) and no Widerrufsbelehrung.',
                        props.gaps.length,
                    )
                }}
                {{
                    $t(
                        'Publish the missing text under exactly the slug named below. A policy under any other slug is never found, for example',
                    )
                }}
                <code>agb</code>.
            </p>

            <ul class="mt-3 flex flex-col gap-2">
                <li
                    v-for="gap in props.gaps"
                    :key="gap.brandId"
                    class="text-sm"
                >
                    <span class="font-medium">{{ gap.brandName }}</span>
                    — {{ $t('missing') }}
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
                            {{
                                $t('Create :role for :brand', {
                                    role: roleName(slug),
                                    brand: gap.brandName,
                                })
                            }}
                        </Link>
                    </span>

                    <slot name="actions" :gap="gap" />
                </li>
            </ul>
        </div>
    </section>
</template>
