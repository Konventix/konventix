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
 * Renders nothing when nothing applies here — a checkout must never claim
 * someone agreed to a document that does not exist.
 *
 * Since ADR-0066 the contribution also carries `notices`: the texts that
 * apply to this purchase but whose published version does not require
 * consent (D-CPL-08). `requires_consent` alone decides which list a text
 * lands in — there is no role column and no third role — and the two lists
 * keep the resolver's order (`terms`, `withdrawal`, then by name). A
 * purchase under information-only texts is still completable: `required`
 * stays false, no checkbox is shown, and the notice is read rather than
 * agreed to.
 */
const accepted = defineModel<boolean>({ default: false });

const props = defineProps<{
    required: boolean;
    policies: ConsentPolicy[];
    /**
     * Texts that apply here but are not agreed to — the published version
     * says `requires_consent: false` (D-CPL-08). Optional, because a
     * contribution written before ADR-0066 carried none.
     */
    notices?: ConsentPolicy[];
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

const notices = computed<ConsentPolicy[]>(() => props.notices ?? []);

// Rendered whenever *anything* applies: an information-only text still has
// to be put in front of the payer, and only the checkbox depends on
// `required`.
const anything = computed(() => props.required || notices.value.length > 0);
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

        <p :id="noticeId" class="text-xs text-text-muted">
            {{ props.exemptionNotice }}
        </p>

        <div :id="errorId">
            <InputError :message="props.error" />
        </div>
    </div>
</template>
