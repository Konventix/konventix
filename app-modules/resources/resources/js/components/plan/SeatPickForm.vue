<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import AdmissionSeatController from '@/actions/Konventix/Resources/Http/Controllers/AdmissionSeatController';
import SeatPickController from '@/actions/Konventix/Resources/Http/Controllers/SeatPickController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { firstPlaceIdsError } from './eligibility';

/**
 * The "Save my seat" post (design §7.4): a plain form of `placeIds[]` to
 * `SeatPickController@store`, or to `AdmissionSeatController@store` in the
 * per-ticket mode (#292 §6.4) — the Action and the exclusion constraint
 * decide, the page only refuses what it can already see. Nothing is
 * persisted before this submits, which is what lets a click on another
 * place swap the selection freely (`selectWithSwap`). Split out of
 * `Pick.vue` in the 2026-09-21 bugfix round for size only.
 *
 * A save answers with a redirect back onto the picker (#317). The page
 * stays mounted because Inertia preserves state on a non-GET visit by
 * default, and it stays scrolled because of `:options="{ preserveScroll:
 * true }"`: Inertia 3's `<Form>` has no `preserve-scroll` prop, so the
 * attribute form `<Link>` accepts is a silent no-op here and the page
 * jumped to the top. `Pick.vue` takes the fresh selection and
 * availability over from the new props.
 */
defineProps<{
    registrationId: string;
    admissionId: string | null;
    selected: string[];
    announcement: string;
}>();
</script>

<template>
    <Form
        v-bind="
            admissionId
                ? AdmissionSeatController.store.form({ admission: admissionId })
                : SeatPickController.store.form(registrationId)
        "
        #default="{ errors, processing }"
        :options="{ preserveScroll: true }"
        class="flex flex-col gap-4"
    >
        <input
            v-for="id in selected"
            :key="id"
            type="hidden"
            name="placeIds[]"
            :value="id"
        />

        <InputError :message="firstPlaceIdsError(errors)" />

        <div class="flex flex-wrap items-center gap-3">
            <Button
                type="submit"
                :disabled="processing || selected.length === 0"
            >
                {{ processing ? 'Saving…' : 'Save my seat' }}
            </Button>
            <span class="text-sm text-text-muted">{{ announcement }}</span>
        </div>
    </Form>
</template>
