<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Ticket as TicketIcon } from '@lucide/vue';
import { computed } from 'vue';
import TicketDownloadController from '@/actions/Konventix/Admission/Http/Controllers/TicketDownloadController';
import TicketManagerRow from './TicketManagerRow.vue';

/**
 * Admission's contribution to Registration's account page (#292 §4.5,
 * #16), registered by `AdmissionServiceProvider::registerSlots()` on
 * `registration.mine.show`: the buyer's ticket manager — one row per
 * admission with its holder, invitation and seat — plus the download of
 * the combined file. `manager` is null for a registration that granted
 * no admissions, or whose admissions have all been revoked, and then this
 * renders nothing (`SeatSummarySlot.vue`'s idiom on this same slot).
 *
 * The download is a plain anchor, not an Inertia `<Link>`: it streams a
 * PDF, and an Inertia visit would expect a page component back.
 *
 * A refused holder change comes back as the `holder` form error; it is
 * shown under every row rather than the one it belongs to, since the
 * error bag does not say which — one row at a time is all a person can
 * click anyway.
 */
type Manager = Konventix.Admission.Data.TicketManagerData;

const props = defineProps<{
    manager: Manager | null;
}>();

const page = usePage();

const holderError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.holder,
);
</script>

<template>
    <section
        v-if="props.manager"
        class="flex flex-col gap-3"
        aria-labelledby="registration-tickets"
    >
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h2 id="registration-tickets" class="text-lg font-medium">
                {{
                    $tChoice(
                        '{1} Ticket|[2,*] Tickets',
                        props.manager.ticketCount,
                    )
                }}
            </h2>
            <span class="flex-1" />
            <a
                :href="
                    TicketDownloadController.buyer.url(
                        props.manager.registrationId,
                    )
                "
                class="inline-flex min-h-9 items-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-text hover:border-accent"
            >
                <TicketIcon class="size-4" aria-hidden="true" />
                {{
                    $tChoice(
                        '{1} Download ticket (PDF)|[2,*] Download all tickets (PDF)',
                        props.manager.ticketCount,
                    )
                }}
            </a>
        </div>

        <p class="text-sm text-text-muted">
            {{
                $t(
                    'Each ticket admits one person. Claim one for yourself, invite the others, and everyone can choose their own seat.',
                )
            }}
        </p>

        <ul class="flex flex-col gap-3">
            <TicketManagerRow
                v-for="admission in props.manager.admissions"
                :key="admission.id"
                :admission="admission"
                :manager-holds-one="props.manager.holdsOne"
                :error="holderError"
            />
        </ul>
    </section>
</template>
