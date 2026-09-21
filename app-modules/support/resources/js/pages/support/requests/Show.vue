<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index as myRequests } from '@/routes/support/requests/mine';
import {
    supportCategorySummary,
    supportStateClass,
    supportStateLabel,
} from '../../../components/requests/supportConsole';
import SupportContextCard from '../../../components/requests/SupportContextCard.vue';
import SupportReporterThread from '../../../components/requests/SupportReporterThread.vue';

/**
 * One report as its reporter reads it (design §8) — **one page for both
 * ways in**. Signed in, it is reached from the account menu and the session
 * authorizes it; as a guest it is reached from the 30-day signed link in
 * the receipt and the signature authorizes it. Everything below that line
 * is identical, which is why it is one component and not two: the moment
 * they diverge is the moment one of them quietly stops hiding internal
 * notes.
 *
 * `replyUrl` is the one prop that differs, and the server builds it —
 * relative for an account, signed and expiring with the incoming link for
 * a guest. The page never mints a URL of its own for it.
 *
 * `SupportContextCard` is the console's component, reused unchanged. It is
 * already the right thing: it renders exactly the fields the report dialog
 * disclosed before sending ("What we send with your report"), so a person
 * sees afterwards precisely what they were promised beforehand. The two
 * "Find in logs" buttons disappear by themselves here, because the server
 * passes no log URLs — that dashboard is the operator's.
 *
 * a11y (§12): `h1` is the page title; the thread and the technical details
 * are `<section>`s under their own `h2`, the second one visually hidden
 * because the card carries its own visible title. No heading level is
 * skipped.
 */
const props = defineProps<{
    request: Konventix.Support.Data.SupportReporterRequestData;
    replyUrl: string;
    isGuestLink: boolean;
}>();

/**
 * The card wants a brand label and the reporter's snapshot already carries
 * the slug the server recorded; `Console` for a report filed from the
 * operator host, which is the same word every other surface prints.
 */
const brandLabel = computed(() => {
    const brand = props.request.context.brand;

    return brand === null || brand === ''
        ? '—'
        : brand.charAt(0).toUpperCase() + brand.slice(1);
});
</script>

<template>
    <Head :title="`Report ${props.request.reference}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6">
        <div class="flex flex-col gap-3">
            <Button
                v-if="!props.isGuestLink"
                variant="outline"
                class="self-start"
                as-child
            >
                <Link :href="myRequests().url">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    All my reports
                </Link>
            </Button>

            <Heading
                as="h1"
                :title="`Report ${props.request.reference}`"
                :description="supportCategorySummary(props.request.categories)"
            />

            <p class="text-sm">
                <span class="text-text-muted">Status:</span>
                <span
                    class="ml-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="supportStateClass(props.request.state)"
                >
                    {{ supportStateLabel(props.request.state) }}
                </span>
            </p>

            <p v-if="props.isGuestLink" class="text-sm text-text-muted">
                You are reading this through the link we mailed you. It works
                for 30 days from the day it was sent; if it stops working, the
                page you land on will offer you a new one.
            </p>
        </div>

        <SupportReporterThread
            :message="props.request.message"
            :created-at="props.request.createdAt"
            :messages="props.request.messages"
            :can-reply="props.request.canReply"
            :reply-url="props.replyUrl"
        />

        <section aria-labelledby="support-context-heading">
            <h2 id="support-context-heading" class="sr-only">
                What you sent with this report
            </h2>
            <SupportContextCard
                :context="props.request.context"
                :brand-label="brandLabel"
                :page-logs-url="null"
                :submit-logs-url="null"
            />
        </section>
    </div>
</template>
