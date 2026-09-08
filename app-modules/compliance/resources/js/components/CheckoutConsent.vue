<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { consentLabelSegments } from './consentLabel';
import type { ConsentPolicy } from './consentLabel';

/**
 * The consent control every public purchase path renders before its order
 * button (GitLab #2), contributed to the `checkout.consent` slot by
 * Compliance and rendered by Commerce's cart and payment pages.
 *
 * It lives here, not in either checkout, because the wording is the legally
 * significant part: two checkouts writing their own would drift, and the
 * module that owns the documents is the one that should say what agreeing to
 * them means.
 *
 * One control for both documents, unlike `policies/Required.vue`'s one
 * checkbox per policy. That page captures *consent* under Art. 6(1)(a),
 * where Art. 7(2) forbids bundling; this is a contractual declaration
 * (`LawfulBasis::Contract`) made when concluding a purchase, where the AGB
 * and the withdrawal information are two halves of one act.
 *
 * The exemption notice is not a link and not collapsible: § 312g Abs. 2
 * Nr. 9 BGB removes a right the payer would otherwise have, and a payer who
 * has to open something to find that out has not been told.
 *
 * Renders nothing when nothing is published (`required` false) — a checkout
 * must never claim someone agreed to a document that does not exist.
 */
const accepted = defineModel<boolean>({ default: false });

const props = defineProps<{
    required: boolean;
    policies: ConsentPolicy[];
    exemptionNotice: string;
    error?: string;
    /**
     * Where the payer has to settle an outstanding *contract* agreement
     * before this purchase may proceed (GitLab #205), or null — the server
     * side of the same slot contribution, which the checkout controllers
     * read to refuse the order.
     *
     * Declared but not rendered, on purpose. Every page that renders this
     * control now redirects on GET while it is set
     * (`CartController::show()`, `CheckoutController::show()` and
     * `::showOrder()`), so the payer never reaches a form in that state and
     * a notice here would be unreachable. Declaring it is what stops Vue
     * falling it through onto the root element as
     * `outstandingurl="/policies-required"` (review 6 finding 6).
     */
    outstandingUrl?: string | null;
}>();

const checkboxId = 'checkout-accept-terms';
const noticeId = 'checkout-consent-notice';
const errorId = 'checkout-consent-error';

// ADR-0055: the error is linked to the control, not merely printed near it.
const describedBy = computed(() =>
    props.error ? `${noticeId} ${errorId}` : noticeId,
);

// The wording lives in a pure function, not in the template: Vue's default
// `whitespace: 'condense'` drops a whitespace-only text node between two
// elements, which silently glued "the" onto the first document's name and
// "and" onto the second (fix round 1). Interpolated strings survive
// verbatim, and the spacing is testable without rendering anything —
// `consentLabel.test.ts`.
const labelSegments = computed(() => consentLabelSegments(props.policies));
</script>

<template>
    <div v-if="props.required" class="flex flex-col gap-2">
        <div class="flex items-start gap-2">
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

        <p :id="noticeId" class="text-xs text-text-muted">
            {{ props.exemptionNotice }}
        </p>

        <div :id="errorId">
            <InputError :message="props.error" />
        </div>
    </div>
</template>
