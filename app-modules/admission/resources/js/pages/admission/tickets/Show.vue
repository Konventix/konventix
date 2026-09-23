<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarPlus, ClipboardList, FileDown } from '@lucide/vue';
import MyTicketsController from '@/actions/Konventix/Admission/Http/Controllers/MyTicketsController';
import TicketDownloadController from '@/actions/Konventix/Admission/Http/Controllers/TicketDownloadController';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { calendar } from '@/routes/event';
import { show as showRegistration } from '@/routes/registration/mine';
import TicketEventBand from '../../../components/TicketEventBand.vue';
import TicketHolderBand from '../../../components/TicketHolderBand.vue';

/**
 * One ticket, on screen (#292 §4.5): the event band with the banner, the
 * holder band with the scan code, and the ways out — the PDF, the
 * calendar file, and the seat picker when Resources opens it to this
 * viewer. The page offers this one ticket and nothing more (#316): the PDF
 * download is the holder's route, so it shows for the holder only. The
 * registration's combined file lives on the registration page, for its
 * buyer — a manager who does not hold this ticket reaches it through the
 * "Registration" link below, never from here.
 *
 * The two file links are plain anchors, not Inertia `<Link>`s — they
 * stream a download, and an Inertia visit would expect a page back.
 *
 * The manager also gets the way back to the registration the ticket came
 * from — `registration.mine.show` is the registrant's own page, so a
 * holder who did not buy it gets no link they could not open.
 */
// Spelled out rather than `Konventix.Admission.Data.TicketPageData`: a bare
// namespaced type fails the SFC compiler's macro type resolver (see
// `seating/Pick.vue`). Mirrors `TicketPageData`.
type Props = {
    id: string;
    eventId: string;
    eventName: string | null;
    eventSlug: string | null;
    eventWhen: string | null;
    venueLabel: string | null;
    bannerUrl: string | null;
    bannerAlt: string | null;
    role: string;
    state: string;
    validRange: string;
    holderUsername: string | null;
    isHolder: boolean;
    isManager: boolean;
    checkedIn: boolean;
    qrSvg: string;
    doorCode: string;
    seat: Konventix.Admission.Data.TicketSeatData | null;
    registrationId: string | null;
    slots: {
        module: string;
        component: string;
        props: Record<string, unknown>;
    }[];
};

const props = defineProps<Props>();
</script>

<template>
    <Head
        :title="
            props.eventName
                ? $t('Ticket · :event', { event: props.eventName })
                : $t('Ticket')
        "
    />

    <div class="mx-auto w-full max-w-2xl px-4 py-6">
        <Link
            :href="MyTicketsController.index.url()"
            class="mb-4 inline-flex items-center gap-1 text-sm text-text-muted hover:text-text"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ $t('My tickets') }}
        </Link>

        <article
            class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-sm"
            :aria-label="$t('Ticket')"
        >
            <TicketEventBand
                :event-name="props.eventName"
                :event-slug="props.eventSlug"
                :event-when="props.eventWhen"
                :venue-label="props.venueLabel"
                :banner-url="props.bannerUrl"
                :banner-alt="props.bannerAlt"
            />

            <TicketHolderBand
                :qr-svg="props.qrSvg"
                :door-code="props.doorCode"
                :holder-username="props.holderUsername"
                :role="props.role"
                :valid-range="props.validRange"
                :seat-label="props.seat?.placeLabel ?? null"
                :checked-in="props.checkedIn"
            />

            <footer
                class="flex flex-wrap items-center gap-2 border-t border-border bg-surface p-4"
            >
                <a
                    v-if="props.isHolder"
                    :href="TicketDownloadController.holder.url(props.id)"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
                >
                    <FileDown class="size-4" aria-hidden="true" />
                    {{ $t('Download PDF') }}
                </a>
                <Link
                    v-if="props.isManager && props.registrationId"
                    :href="showRegistration(props.registrationId).url"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium text-text hover:border-accent"
                >
                    <ClipboardList class="size-4" aria-hidden="true" />
                    {{ $t('Registration') }}
                </Link>
                <a
                    v-if="props.eventSlug"
                    :href="calendar(props.eventSlug).url"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium text-text hover:border-accent"
                >
                    <CalendarPlus class="size-4" aria-hidden="true" />
                    {{ $t('Add to calendar') }}
                </a>
                <!-- Resources' seat button, when the viewer may pick (§6.4). -->
                <template v-for="slot in props.slots" :key="slot.component">
                    <component
                        :is="resolveRegisteredComponent(slot.component)"
                        v-bind="slot.props"
                    />
                </template>
            </footer>
        </article>
    </div>
</template>
