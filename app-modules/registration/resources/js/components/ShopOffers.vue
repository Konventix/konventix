<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { formatDateTime } from '@/lib/datetime';
import { show as showEvent } from '@/routes/event';
import OfferPrice from './OfferPrice.vue';

/**
 * Registration's contribution to Commerce's `shop.index` slot (#240): what
 * a visitor can sign up for, on the brand-wide shop rather than on one
 * event's landing page.
 *
 * The same `PublicOfferData` the landing page renders, with the event's
 * name filled in — `/shop` has no event in its address, so "Weekend Ticket"
 * alone would not say what it is a ticket to. The name is always there: the
 * contribution's own query lists an offer only where its event has a public,
 * unarchived summary to name it by, so there is no unlabelled-card case to
 * guard against (review finding 3).
 *
 * A registration is not added to the cart from here: ADR-0047 point 3 has
 * it join the cart by *submitting the form first*, which is where the
 * questions and the quota live. So the card links to the form, and the
 * heading says "Register" where the goods above say "Add to cart" —
 * clicking either has to promise what actually happens next.
 *
 * The event's name is its own link to the event's page (Task 17c), on both
 * the on-sale and the announced card: the register link sits beside it as
 * the card's action rather than around it, because a link inside a link is
 * not markup a browser or a screen reader can honour.
 *
 * #218: an offer whose sale has not opened is announced, not linked, as a
 * plain `<li>` that never takes focus; one whose sale is over never reaches
 * this component. The announced date is formatted through the shared
 * `formatDateTime()` — the props were rendered on a server that knows
 * nothing about where the page was opened (#237).
 *
 * The amount follows ADR-0017 to the letter (#240, review finding 8):
 * Registration declares a net amount and a tax *class*, and Commerce — which
 * owns money (ADR-0006) — computes the end price. Since #234 it arrives as
 * `PublicOfferData::$endPrice`, computed behind Platform's
 * `Pricing\EndPriceCalculator` by the very `TaxCalculator` the product cards
 * on this page run through, instead of being patched into this payload by
 * `ShopController` on its way out — which is why this component needed a
 * hand-written intersection type for a field the generator did not know
 * about, and why the landing page could not have one at all. Nothing here
 * does arithmetic on a price, and the wording is `OfferPrice`'s, shared with
 * the landing page and the registration form, because a PAngV end price that
 * read differently three inches apart is exactly what #3 was filed about.
 */
type Offer = Konventix.Registration.Data.PublicOfferData;

const props = defineProps<{
    offers: Offer[];
}>();
</script>

<template>
    <section
        v-if="props.offers.length > 0"
        class="flex flex-col gap-3"
        aria-labelledby="shop-offers"
    >
        <h2 id="shop-offers" class="text-lg font-medium">
            {{ $t('Register') }}
        </h2>

        <ul class="grid gap-4 sm:grid-cols-2">
            <li v-for="offer in props.offers" :key="offer.registrationTypeId">
                <div
                    v-if="offer.onSale"
                    class="flex h-full flex-col gap-2 rounded-xl border border-border bg-surface-raised p-4"
                >
                    <span class="font-medium text-text">{{ offer.name }}</span>
                    <span class="text-sm text-text-muted">
                        <Link
                            v-if="offer.eventSlug"
                            :href="showEvent(offer.eventSlug).url"
                            class="underline-offset-4 hover:text-text hover:underline"
                        >
                            {{ offer.eventName }}
                        </Link>
                        <template v-else>{{ offer.eventName }}</template>
                    </span>
                    <span
                        v-if="offer.requiresApproval"
                        class="text-sm text-text-muted"
                    >
                        {{ $t('Reviewed before it is confirmed') }}
                    </span>

                    <span
                        class="mt-auto flex items-center gap-2 pt-2 text-sm font-medium text-text"
                    >
                        <OfferPrice
                            :price="offer.price"
                            :end-price="offer.endPrice"
                        />
                        <span class="flex-1" />
                        <Link
                            :href="offer.url"
                            class="inline-flex min-h-9 items-center gap-1 rounded-md border border-border px-3 text-sm font-medium text-text hover:border-accent"
                        >
                            {{ $t('Register') }}
                            <ArrowRight
                                class="size-4 shrink-0 text-text-muted"
                                aria-hidden="true"
                            />
                        </Link>
                    </span>
                </div>

                <div
                    v-else
                    class="flex h-full flex-col gap-2 rounded-xl border border-dashed border-border p-4"
                >
                    <span class="font-medium text-text">{{ offer.name }}</span>
                    <span class="text-sm text-text-muted">
                        <Link
                            v-if="offer.eventSlug"
                            :href="showEvent(offer.eventSlug).url"
                            class="underline-offset-4 hover:text-text hover:underline"
                        >
                            {{ offer.eventName }}
                        </Link>
                        <template v-else>{{ offer.eventName }}</template>
                    </span>
                    <span class="text-sm text-text-muted">
                        <template v-if="offer.salesStart">
                            {{ $t('Sale starts') }}
                            <time :datetime="offer.salesStart">
                                {{
                                    formatDateTime(offer.salesStart, {
                                        dateStyle: 'long',
                                    })
                                }}
                            </time>
                        </template>
                        <template v-else>{{ $t('Not on sale') }}</template>
                    </span>

                    <OfferPrice
                        :price="offer.price"
                        :end-price="offer.endPrice"
                        class="mt-auto pt-2 text-sm font-medium text-text"
                    />
                </div>
            </li>
        </ul>
    </section>
</template>
