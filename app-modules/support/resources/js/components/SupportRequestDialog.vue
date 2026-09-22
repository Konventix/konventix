<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { disclosedFields } from './supportContext';
import type { SupportClientContext } from './supportContext';
import {
    counterText,
    localErrors,
    MESSAGE_MAX,
    SUPPORT_CATEGORIES,
    successMessage,
    toggleCategory,
    transportErrorMessage,
} from './supportForm';

/**
 * "Report a problem or give feedback" (design §6.2), in the order §6.2
 * gives — the order is the design, not an accident: what it is about, what
 * happened, how to reach you, what we may do with that, and only then what
 * rides along.
 *
 * **It never navigates.** The submission goes through Inertia v3's
 * `useHttp` as a plain JSON POST, so the page underneath is untouched.
 * Somebody reporting that checkout is broken is standing *on* checkout;
 * replacing that page with a flash message would throw away the state they
 * are complaining about, and with it any chance of them trying again.
 *
 * **The endpoint arrives as a prop.** Two routes back this one dialog — the
 * brand host's and the console's (§6.4) — and which one applies is a fact
 * about the shell, not about the dialog. Passing it in also keeps the
 * component free of a Wayfinder import, which is what lets the logic beside
 * it be tested in vitest's node environment.
 *
 * Accessibility (§12, ADR-0055): the dialog title is the accessible name,
 * the chips are toggle buttons in a labelled group announcing
 * `aria-pressed`, the counter and any error are wired to the textarea
 * through `aria-describedby`, a failed submit moves focus to the first
 * field that is wrong, and reka-ui traps focus and restores it on Escape.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        /** The route this host posts to (§6.4). */
        endpoint: string;
        /** No account behind this visitor: an address is required. */
        isGuest: boolean;
        /**
         * Shown to an account instead of an input, and never posted — an
         * account's address belongs to Identity and is resolved from the
         * account id when a mail needs it. Null today: the shared
         * `auth.user` prop deliberately does not carry an address
         * (ADR-0051 §5), so the dialog names the account rather than the
         * address until some surface has a reason to pass one.
         */
        accountEmail?: string | null;
        /** Snapshot taken at submit time, never earlier. */
        context: () => SupportClientContext;
        /**
         * The active known issues for this brand (§9.4), fetched by the
         * launcher from `GET /support/known-issues` when the dialog opens —
         * never on page load, and never on the console, which has no brand
         * whose visitors need warning. An empty list renders nothing.
         */
        knownIssues?: Konventix.Support.Data.KnownIssueData[];
    }>(),
    {
        accountEmail: null,
        knownIssues: () => [],
    },
);

const emit = defineEmits<{ (e: 'close'): void }>();

const baseId = useId();
const messageId = `${baseId}-message`;
const counterId = `${baseId}-counter`;
const emailId = `${baseId}-email`;
const consentId = `${baseId}-consent`;
const categoriesId = `${baseId}-categories`;
const honeypotId = `${baseId}-website`;

const categories = ref<string[]>([]);
const message = ref('');
const email = ref('');
const contactConsent = ref(false);
/** Never filled by a human (D-SUP-05). */
const honeypot = ref('');
const clientErrors = ref<Record<string, string>>({});
const transportError = ref<string | null>(null);

const form = useHttp<
    {
        categories: string[];
        message: string;
        email: string | null;
        contactConsent: boolean;
        honeypot: string;
        context: SupportClientContext | null;
    },
    { reference: string; isGuest: boolean; requestUrl: string | null }
>({
    categories: [],
    message: '',
    email: null,
    contactConsent: false,
    honeypot: '',
    context: null,
});

const errors = computed<Record<string, string>>(() => ({
    ...(form.errors as Record<string, string>),
    ...clientErrors.value,
}));

const counter = computed(() => counterText(message.value.length));

const messageDescribedBy = computed(() =>
    errors.value.message ? `${counterId} ${messageId}-error` : counterId,
);

const disclosed = computed(() => disclosedFields(props.context()));

/** A fresh dialog every time it opens: a report is not a draft. */
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        categories.value = [];
        message.value = '';
        email.value = '';
        contactConsent.value = false;
        honeypot.value = '';
        clientErrors.value = {};
        transportError.value = null;
        form.clearErrors();
    },
);

function focusFirstInvalid(fields: Record<string, string>): void {
    const target =
        ('categories' in fields && categoriesId) ||
        ('message' in fields && messageId) ||
        ('email' in fields && emailId) ||
        null;

    if (target === null || typeof document === 'undefined') {
        return;
    }

    document.getElementById(target)?.focus();
}

function submit(): void {
    if (form.processing) {
        return;
    }

    transportError.value = null;

    const values = {
        categories: categories.value,
        message: message.value,
        email: email.value,
        contactConsent: contactConsent.value,
    };

    const found = localErrors(values, props.isGuest);
    clientErrors.value = found;

    if (Object.keys(found).length > 0) {
        focusFirstInvalid(found);

        return;
    }

    form.categories = categories.value;
    form.message = message.value;
    // An account's address belongs to Identity and is resolved from the
    // account id when a mail needs it; posting a copy of it would store a
    // second, ageing one (design §6.2 item 5).
    form.email = props.isGuest ? email.value : null;
    form.contactConsent = contactConsent.value;
    form.honeypot = honeypot.value;
    // Snapshot now, not when the dialog opened: the person may have read
    // the "what we send" list and then navigated, and the report is about
    // where they are.
    form.context = props.context();

    form.post(props.endpoint, {
        onSuccess: (response) => {
            toast.success(
                successMessage(
                    response.reference,
                    response.isGuest,
                    email.value,
                ),
            );
            emit('close');
        },
        onError: (fieldErrors) => {
            focusFirstInvalid(fieldErrors as Record<string, string>);
        },
        onHttpException: (response) => {
            transportError.value = transportErrorMessage(response.status);

            return false;
        },
        onNetworkError: () => {
            transportError.value = transportErrorMessage(null);

            return false;
        },
    });
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value: boolean) => !value && emit('close')"
    >
        <!--
            Scrollable, and `dvh` rather than `vh`: this dialog is taller than
            a phone screen once the category chips wrap and the disclosure is
            open, and without a height bound the footer — Cancel and Send —
            sat below the fold with nothing to scroll (reported on a phone
            after the 0.8.0 rollout). `vh` is the *largest* viewport on
            mobile, the one with the browser chrome hidden, so it leaves the
            same fold on a first load; `dvh` is the visible one.

            `max-w-lg` is deliberately gone rather than moved: the primitive
            already carries `max-w-[calc(100%-2rem)] … sm:max-w-lg`, and
            setting an unprefixed `max-w-lg` here beat the first of those
            through tailwind-merge — which is why the dialog also sat edge to
            edge on a narrow screen instead of keeping its gutter.
        -->
        <DialogContent class="max-h-[85dvh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Report a problem or give feedback</DialogTitle>
                <DialogDescription>
                    Tell us what happened. We send some technical details along
                    so we can find it — you can see exactly what below.
                </DialogDescription>
            </DialogHeader>

            <!--
                Known-issue banner (design §6.2 item 2, §9.4). One per
                notice, above everything else in the dialog: the whole point
                is to be read before the person starts typing. `role="status"`
                rather than `alert` — it is information, not an interruption,
                and a report is still welcome afterwards.
            -->
            <div
                v-for="issue in knownIssues"
                :key="issue.id"
                role="status"
                class="rounded-md border border-border bg-surface-muted p-3 text-sm text-text-muted"
            >
                <p class="font-medium text-text">We know about this</p>
                <p>{{ issue.notice }}</p>
                <p>
                    You do not need to report it again, but you can add details.
                </p>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium">
                        What is this about?
                    </legend>
                    <div
                        :id="categoriesId"
                        role="group"
                        aria-label="What is this about?"
                        class="flex flex-wrap gap-2"
                        tabindex="-1"
                    >
                        <button
                            v-for="option in SUPPORT_CATEGORIES"
                            :key="option.value"
                            type="button"
                            :aria-pressed="categories.includes(option.value)"
                            class="min-h-11 rounded-full border px-3 py-1 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :class="
                                categories.includes(option.value)
                                    ? 'border-accent bg-accent text-on-accent'
                                    : 'border-border bg-surface text-text hover:bg-subtle'
                            "
                            @click="
                                categories = toggleCategory(
                                    categories,
                                    option.value,
                                )
                            "
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <InputError :message="errors.categories" />
                </fieldset>

                <div class="grid gap-2">
                    <Label :for="messageId">What happened?</Label>
                    <Textarea
                        :id="messageId"
                        v-model="message"
                        class="min-h-28"
                        :maxlength="MESSAGE_MAX"
                        :aria-describedby="messageDescribedBy"
                        :aria-invalid="errors.message ? 'true' : undefined"
                    />
                    <p :id="counterId" class="text-xs text-text-muted">
                        {{ counter }}
                    </p>
                    <div :id="`${messageId}-error`">
                        <InputError :message="errors.message" />
                    </div>
                </div>

                <div v-if="isGuest" class="grid gap-2">
                    <Label :for="emailId">Your e-mail address</Label>
                    <Input
                        :id="emailId"
                        v-model="email"
                        type="email"
                        autocomplete="email"
                        :aria-describedby="
                            errors.email ? `${emailId}-error` : undefined
                        "
                        :aria-invalid="errors.email ? 'true' : undefined"
                    />
                    <div :id="`${emailId}-error`">
                        <InputError :message="errors.email" />
                    </div>
                </div>
                <p v-else class="text-sm text-text-muted">
                    We will answer at
                    {{ accountEmail ?? 'the address on your account' }}.
                </p>

                <div class="flex items-start gap-2">
                    <Checkbox
                        :id="consentId"
                        :model-value="contactConsent"
                        class="mt-0.5"
                        @update:model-value="
                            (value: boolean | 'indeterminate') =>
                                (contactConsent = value === true)
                        "
                    />
                    <Label :for="consentId" class="text-sm font-normal">
                        You may contact me about this report.
                        <span v-if="isGuest" class="block text-text-muted">
                            We send you a confirmation with your access link
                            either way.
                        </span>
                    </Label>
                </div>

                <Collapsible>
                    <CollapsibleTrigger
                        class="text-sm underline underline-offset-4"
                    >
                        What we send with your report
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <dl class="mt-2 grid gap-1 text-sm text-text-muted">
                            <div
                                v-for="field in disclosed"
                                :key="field.label"
                                class="flex flex-wrap gap-x-2"
                            >
                                <dt class="font-medium">{{ field.label }}:</dt>
                                <dd class="break-all">{{ field.value }}</dd>
                            </div>
                        </dl>
                    </CollapsibleContent>
                </Collapsible>

                <!--
                    The honeypot (D-SUP-05): no label a screen reader would
                    read out, out of the tab order, and off every autofill.
                    A filled one answers a convincing 201 and stores nothing.
                -->
                <div aria-hidden="true" class="sr-only">
                    <label :for="honeypotId">Website</label>
                    <input
                        :id="honeypotId"
                        v-model="honeypot"
                        type="text"
                        name="website"
                        tabindex="-1"
                        autocomplete="off"
                    />
                </div>

                <p
                    v-if="transportError"
                    role="alert"
                    class="text-sm text-danger"
                >
                    {{ transportError }}
                </p>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Send
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
