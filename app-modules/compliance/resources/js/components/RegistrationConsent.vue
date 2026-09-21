<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { consentLabelSegments } from './consentLabel';
import type { ConsentPolicy } from './consentLabel';

/**
 * The declaration control on the public registration form (ADR-0066 §6.2),
 * contributed to Registration's own `registration.consent` slot.
 *
 * `CheckoutConsent.vue`'s sibling, and deliberately its mirror rather than a
 * shared component with a mode flag: the wording of a purchase declaration
 * and the wording of a registration declaration are two legal texts about
 * two acts, and a shared one would be reworded for one surface and silently
 * change the other.
 *
 * Two differences from the checkout's control, both substantive. There is no
 * § 312g Abs. 2 Nr. 9 BGB exemption notice — that sentence is about an order
 * and belongs where the order is placed. And the texts here are the ones
 * bound to *this* registration: the event, the type being bought and the
 * entry conditions it carries, resolved by Compliance from a context
 * Registration builds and never interprets.
 *
 * `requires_consent` alone decides agree versus inform (D-CPL-08); the
 * ordering is the resolver's, `terms` and `withdrawal` first and the rest by
 * name. Renders nothing when nothing applies.
 */
const accepted = defineModel<boolean>({ default: false });

const props = defineProps<{
    required: boolean;
    policies: ConsentPolicy[];
    /** Texts that apply but are read rather than agreed to (D-CPL-08). */
    notices?: ConsentPolicy[];
    error?: string;
    /**
     * Where the registrant has to settle an outstanding *contract*
     * agreement first (#205), or null. Declared but not rendered, exactly as
     * on the checkout control — the page that renders this redirects on GET
     * while it is set, so a notice here would be unreachable, and declaring
     * it is what stops Vue falling it through onto the root element.
     */
    outstandingUrl?: string | null;
}>();

const checkboxId = 'registration-accept-legal-texts';
const errorId = 'registration-consent-error';

const notices = computed<ConsentPolicy[]>(() => props.notices ?? []);
const anything = computed(() => props.required || notices.value.length > 0);

// ADR-0055: the error is linked to the control it belongs to.
const describedBy = computed(() => (props.error ? errorId : undefined));

// A pure function rather than template text nodes, for the reason
// `CheckoutConsent.vue` gives: Vue's `whitespace: 'condense'` eats a
// whitespace-only node between two elements and glues the words together.
const labelSegments = computed(() => consentLabelSegments(props.policies));
</script>

<template>
    <div v-if="anything" class="flex flex-col gap-2">
        <div v-if="props.required" class="flex items-start gap-2">
            <Checkbox
                :id="checkboxId"
                :model-value="accepted"
                aria-required="true"
                :aria-invalid="props.error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="mt-0.5"
                @update:model-value="
                    (value: boolean | 'indeterminate') => {
                        accepted = value === true;
                    }
                "
            />
            <label :for="checkboxId" class="text-sm text-text">
                <template
                    v-for="(segment, index) in labelSegments"
                    :key="index"
                >
                    <Link
                        v-if="segment.kind === 'link'"
                        :href="segment.url"
                        class="underline underline-offset-4"
                        target="_blank"
                        >{{ segment.text }}</Link
                    >
                    <template v-else>{{ segment.text }}</template>
                </template>
            </label>
        </div>

        <p v-if="notices.length > 0" class="text-sm text-text">
            Please read:
            <template v-for="(notice, index) in notices" :key="notice.slug">
                <template v-if="index > 0">, </template>
                <Link
                    :href="notice.url"
                    class="underline underline-offset-4"
                    target="_blank"
                    >{{ notice.name }}</Link
                >
            </template>
        </p>

        <div :id="errorId">
            <InputError :message="props.error" />
        </div>
    </div>
</template>
