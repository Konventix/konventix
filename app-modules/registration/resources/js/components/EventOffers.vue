<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { useId } from 'vue';
import { formatDateTime } from '@/lib/datetime';

/**
 * Registration's contribution to Event's `event.landing` slot: what a
 * visitor can sign up for, and the only route from a brand's public pages
 * into the registration flow.
 *
 * The offers arrive as props resolved server-side (see
 * `RegistrationServiceProvider::registerSlots()`), so the call to action is
 * in the first paint rather than one fetch later.
 *
 * Both `/register` routes require an account (D-REG-08). That is not said
 * here: the link goes to the form, and `auth` sends a signed-out visitor
 * through login and back. Announcing the requirement in front of the button
 * only adds a reason to stop.
 *
 * The announced start is formatted through the shared `formatDateTime()`
 * (#208): the props were rendered on a server that knows nothing about where
 * the page was opened, so the visitor's own zone is the only one the
 * announcement can honestly claim — and one implementation of that decision
 * is enough (#237).
 *
 * #218: an offer that is not on sale yet is announced, not linked — there is
 * nothing to click until the window opens, and a link that leads to a refusal
 * is worse than a date. It renders as a plain `<li>` rather than a disabled
 * link so it never takes focus, and the date sits in a `<time>` element with
 * the machine-readable instant beside the human wording. An offer whose sale
 * has ended never reaches this component at all.
 *
 * The `tickets-for-event` static-page block (`blocks/TicketsForEventBlock.vue`)
 * renders the same markup with its own heading, so the heading is a prop
 * defaulting to the landing page's "Register", and the heading id comes
 * from `useId()` — a composed page may carry the block twice.
 */
type Offer = Konventix.Registration.Data.PublicOfferData;

const props = withDefaults(
    defineProps<{
        offers: Offer[];
        heading?: string;
    }>(),
    { heading: 'Register' },
);

const headingId = useId();
</script>

<template>
    <section
        v-if="props.offers.length > 0"
        class="flex flex-col gap-3"
        :aria-labelledby="headingId"
    >
        <h2 :id="headingId" class="text-lg font-medium">{{ props.heading }}</h2>

        <ul class="flex flex-col gap-3">
            <li v-for="offer in props.offers" :key="offer.registrationTypeId">
                <Link
                    v-if="offer.onSale"
                    :href="offer.url"
                    class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="font-medium text-text">
                            {{ offer.name }}
                        </span>
                        <span
                            v-if="offer.requiresApproval"
                            class="text-sm text-text-muted"
                        >
                            Reviewed before it is confirmed
                        </span>
                    </span>

                    <span
                        v-if="offer.price"
                        class="shrink-0 text-sm whitespace-nowrap text-text-muted"
                    >
                        {{ offer.price.unitAmountNet }} €
                        {{ offer.price.display === 'net' ? 'excl. VAT' : '' }}
                    </span>
                    <span v-else class="shrink-0 text-sm text-text-muted">
                        Free
                    </span>

                    <ArrowRight class="size-4 shrink-0 text-text-muted" />
                </Link>

                <div
                    v-else
                    class="flex items-center gap-4 rounded-xl border border-dashed border-border p-4"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="font-medium text-text">
                            {{ offer.name }}
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
                            <template v-else> Not on sale </template>
                        </span>
                    </span>

                    <span
                        v-if="offer.price"
                        class="shrink-0 text-sm whitespace-nowrap text-text-muted"
                    >
                        {{ offer.price.unitAmountNet }} €
                        {{ offer.price.display === 'net' ? 'excl. VAT' : '' }}
                    </span>
                    <span v-else class="shrink-0 text-sm text-text-muted">
                        Free
                    </span>
                </div>
            </li>
        </ul>
    </section>
</template>
