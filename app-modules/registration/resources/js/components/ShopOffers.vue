<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { formatDateTime } from '@/lib/datetime';
import { displayUnitAmount, taxNote } from '@/lib/purchaseTexts';

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
 * #218: an offer whose sale has not opened is announced, not linked, as a
 * plain `<li>` that never takes focus; one whose sale is over never reaches
 * this component. The announced date is formatted through the shared
 * `formatDateTime()` — the props were rendered on a server that knows
 * nothing about where the page was opened (#237).
 *
 * The amount follows ADR-0017 to the letter (#240, review finding 8):
 * Registration declares a net amount and a tax *class*, and Commerce — which
 * owns money (ADR-0006) — computes the end price and writes `unitAmountGross`
 * back into the payload on its way out of `ShopController`. Nothing here does
 * arithmetic on a price, and the wording comes from the shared
 * `@/lib/purchaseTexts` the product cards on this page use, because a PAngV
 * end price that read differently three inches apart is exactly what #3 was
 * filed about.
 */
/**
 * `unitAmountGross` is not on `PriceDeclarationData` — Registration cannot
 * compute it — so it is declared here as what Commerce adds to the payload
 * for this page.
 */
type OfferPrice = Konventix.Registration.Data.PriceDeclarationData & {
    unitAmountGross: string;
    /**
     * False under the operator's §19 UStG election (ADR-0030), where the
     * amount contains no VAT at all — only Commerce knows that, so it says
     * so in the payload (branch review finding 12).
     */
    includesTax: boolean;
};

type Offer = Omit<Konventix.Registration.Data.PublicOfferData, 'price'> & {
    price: OfferPrice | null;
};

const props = defineProps<{
    offers: Offer[];
}>();

/** Which of the two amounts this brand's `price_display` says to print (#3). */
function amount(price: OfferPrice): string {
    return displayUnitAmount({
        unitAmountNet: price.unitAmountNet,
        unitAmountGross: price.unitAmountGross,
        priceDisplay: price.display,
    });
}
</script>

<template>
    <section
        v-if="props.offers.length > 0"
        class="flex flex-col gap-3"
        aria-labelledby="shop-offers"
    >
        <h2 id="shop-offers" class="text-lg font-medium">Register</h2>

        <ul class="grid gap-4 sm:grid-cols-2">
            <li v-for="offer in props.offers" :key="offer.registrationTypeId">
                <Link
                    v-if="offer.onSale"
                    :href="offer.url"
                    class="flex h-full flex-col gap-2 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="font-medium text-text">{{ offer.name }}</span>
                    <span class="text-sm text-text-muted">
                        {{ offer.eventName }}
                    </span>
                    <span
                        v-if="offer.requiresApproval"
                        class="text-sm text-text-muted"
                    >
                        Reviewed before it is confirmed
                    </span>

                    <span
                        class="mt-auto flex items-center gap-2 pt-2 text-sm font-medium text-text"
                    >
                        <template v-if="offer.price">
                            {{ amount(offer.price) }} €
                            <span class="font-normal text-text-muted">
                                {{
                                    taxNote(
                                        offer.price.display,
                                        offer.price.includesTax,
                                    )
                                }}
                            </span>
                        </template>
                        <template v-else>Free</template>
                        <span class="flex-1" />
                        <ArrowRight
                            class="size-4 shrink-0 text-text-muted"
                            aria-hidden="true"
                        />
                    </span>
                </Link>

                <div
                    v-else
                    class="flex h-full flex-col gap-2 rounded-xl border border-dashed border-border p-4"
                >
                    <span class="font-medium text-text">{{ offer.name }}</span>
                    <span class="text-sm text-text-muted">
                        {{ offer.eventName }}
                    </span>
                    <span class="text-sm text-text-muted">
                        <template v-if="offer.salesStart">
                            Sale starts
                            <time :datetime="offer.salesStart">
                                {{
                                    formatDateTime(offer.salesStart, {
                                        dateStyle: 'long',
                                    })
                                }}
                            </time>
                        </template>
                        <template v-else>Not on sale</template>
                    </span>

                    <span class="mt-auto pt-2 text-sm font-medium text-text">
                        <template v-if="offer.price">
                            {{ amount(offer.price) }} €
                            <span class="font-normal text-text-muted">
                                {{
                                    taxNote(
                                        offer.price.display,
                                        offer.price.includesTax,
                                    )
                                }}
                            </span>
                        </template>
                        <template v-else>Free</template>
                    </span>
                </div>
            </li>
        </ul>
    </section>
</template>
