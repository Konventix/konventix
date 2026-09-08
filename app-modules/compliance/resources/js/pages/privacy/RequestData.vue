<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCalendarDate } from '@/lib/datetime';
import { store } from '@/routes/data-requests/public';

/**
 * The public Art. 15 / Art. 17 entry point (ADR-0050 §4): anyone may ask
 * what we hold, whether or not they ever had an account. A submission never
 * opens a request by itself — see `PublicDataRequestController` — so this
 * page has three faces: the form, "check your mail" after a submission, and
 * the deadline once a mailed link has been followed.
 *
 * The submitted-state message is identical for every address on purpose
 * (ADR-0050 §5): this page must never reveal whether an address is known.
 */
type Props = {
    status?: string;
    opened?: { deadline: string } | null;
};

const props = defineProps<Props>();

/**
 * The statutory deadline is a *day* — `deadline_at->toDateString()` — and
 * `formatCalendarDate()` is what prints one (#186/#197 review finding 4): read
 * as an instant, `2026-10-08` is UTC midnight and a reader in Honolulu is told
 * the 7th, a day earlier than the law and the sweep both say.
 */
function formatDeadline(value: string): string {
    return formatCalendarDate(value);
}
</script>

<template>
    <Head title="Request your data" />

    <div
        class="mx-auto flex w-full max-w-lg flex-col gap-6 p-6 text-text lg:p-8"
    >
        <header class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Request your data</h1>
            <p class="text-sm text-text-muted">
                Ask for a copy of what we hold about you, or for it to be
                deleted — whether or not you have an account with us.
            </p>
        </header>

        <div
            v-if="props.opened"
            role="status"
            class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4"
        >
            <p class="font-medium">Your request is open.</p>
            <p class="text-sm text-text-muted">
                We will answer by
                {{ formatDeadline(props.opened.deadline) }} at the latest, at
                the address you just confirmed.
            </p>
        </div>

        <div
            v-else-if="props.status === 'verification-sent'"
            role="status"
            class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4"
        >
            <p class="font-medium">Check your mail.</p>
            <p class="text-sm text-text-muted">
                If that address is one we can act on, we have sent a link to
                confirm your request. It expires in 24 hours.
            </p>
        </div>

        <Form
            v-else
            v-bind="store.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="you@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">What do you need?</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="type"
                        value="export"
                        checked
                        class="size-4 accent-accent"
                    />
                    A copy of my data
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="type"
                        value="deletion"
                        class="size-4 accent-accent"
                    />
                    Deletion
                </label>
                <InputError :message="errors.type" />
            </fieldset>

            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                Send me a confirmation link
            </Button>
        </Form>
    </div>
</template>
