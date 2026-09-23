<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { nextTick, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { link as reissueLink } from '@/routes/support/requests';

/**
 * Where a guest lands when their link no longer verifies (design §8.2):
 * expired after 30 days, wrapped by a mail client, or retyped by hand.
 *
 * The one thing this page must not be is a 403. The person holding that
 * URL is not probing a signature — they reported five weeks ago that the
 * shop was broken and clicked the mail again — and "Invalid signature"
 * tells them their own report is none of their business. So the failure is
 * a screen with one button on it.
 *
 * The form takes the reference and nothing else. There is no address field
 * and there must never be one: the fresh link always goes to the address
 * stored on the report, so holding a reference can never be used to move
 * somebody else's report to an inbox of your choosing. The reference is
 * prefilled from the id in the dead URL when there was one, and stays
 * editable for somebody typing it off the receipt instead.
 *
 * It posts through `useHttp` rather than as an Inertia visit, the same way
 * the report dialog does and for a related reason: there is nowhere to
 * navigate *to*. A redirect would come back through the same dead link and
 * re-render this page from scratch, losing the sentence that says what
 * happened; staying put lets the answer be announced where the person is
 * standing.
 *
 * The answer says "if that reference belongs to a report" and says it for
 * every input, including references that never existed — this page is not
 * an oracle for which eight-character strings are real.
 *
 * a11y (§12): one `h1`, a real `<label>`, the hint and any error linked
 * through `aria-describedby`, and the result announced in a `role="status"`
 * region that focus moves to afterwards.
 */
const props = defineProps<{ reference: string | null }>();

const reference = ref(props.reference ?? '');
const status = ref<HTMLElement | null>(null);
const announcement = ref('');

const form = useHttp<{ reference: string }, { ok: boolean }>({
    reference: '',
});

function submit(): void {
    if (form.processing) {
        return;
    }

    announcement.value = '';
    form.reference = reference.value;

    form.post(reissueLink().url, {
        onSuccess: () => {
            announce(
                trans(
                    'If that reference belongs to a report, we have sent a fresh link to the address it was filed with. It can take a few minutes to arrive.',
                ),
            );
        },
        onHttpException: (response) => {
            announce(
                response.status === 429
                    ? trans(
                          'That is too many requests for one reference. Please try again in an hour.',
                      )
                    : trans(
                          'Something went wrong on our side. Please try again.',
                      ),
            );

            return false;
        },
        onNetworkError: () => {
            announce(
                trans(
                    'Your request did not reach us. Please check your connection and try again.',
                ),
            );

            return false;
        },
    });
}

function announce(sentence: string): void {
    announcement.value = sentence;

    void nextTick(() => status.value?.focus());
}
</script>

<template>
    <Head :title="$t('That link has expired')" />

    <div class="mx-auto flex w-full max-w-lg flex-col gap-6 px-4 py-10">
        <Heading
            as="h1"
            :title="$t('That link has expired')"
            :description="
                $t(
                    'A link to a report works for 30 days. We can send you a new one.',
                )
            "
        />

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="support-link-reference">{{
                    $t('Report reference')
                }}</Label>
                <Input
                    id="support-link-reference"
                    v-model="reference"
                    class="font-mono"
                    autocomplete="off"
                    spellcheck="false"
                    :aria-invalid="form.errors.reference ? 'true' : undefined"
                    aria-describedby="support-link-reference-hint support-link-reference-error"
                />
                <p
                    id="support-link-reference-hint"
                    class="text-xs text-text-muted"
                >
                    {{
                        $t(
                            'The eight characters we printed in the confirmation mail, for example A1B2C3D4.',
                        )
                    }}
                </p>
                <div id="support-link-reference-error">
                    <InputError :message="form.errors.reference" />
                </div>
            </div>

            <Button
                type="submit"
                class="self-start"
                :disabled="form.processing || reference.trim() === ''"
            >
                {{ $t('Send me a new link') }}
            </Button>

            <p
                ref="status"
                tabindex="-1"
                role="status"
                class="text-sm text-text-muted focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none"
            >
                {{ announcement }}
            </p>
        </form>
    </div>
</template>
