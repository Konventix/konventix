<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';

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
        aria-labelledby="event-offers"
    >
        <h2 id="event-offers" class="text-lg font-medium">Register</h2>

        <ul class="flex flex-col gap-3">
            <li v-for="offer in props.offers" :key="offer.registrationTypeId">
                <Link
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
            </li>
        </ul>
    </section>
</template>
