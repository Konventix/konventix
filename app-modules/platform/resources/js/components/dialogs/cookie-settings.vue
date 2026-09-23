<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { clearFunctionalStorage } from '@/lib/cookiePreferences';
import { store as storePreferences } from '@/routes/platform/cookie-preferences';

/**
 * The footer's `cookie-settings` dialog (slice 21b). Two groups from the
 * cookie registry: what is necessary (listed, no control — § 25 Abs. 2
 * Nr. 2 TDDDG) and what is functional (one switch — WP194 §3.6: UI
 * customisation the person switched on, withdrawable here as easily as it
 * was given, Art. 7(3) GDPR). No banner: nothing functional is stored until
 * the person acts on a labelled control.
 */
defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage();
const declared = computed(() => page.props.cookies.declared);

const form = useForm({ functional: page.props.cookies.functional !== false });

function save(): void {
    form.post(storePreferences().url, {
        preserveScroll: true,
        onSuccess: () => {
            // Refusing functional storage must clear what it already holds,
            // not merely stop adding to it (Art. 7(3) GDPR).
            if (!form.functional) {
                clearFunctionalStorage();
            }

            emit('close');
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => !value && emit('close')">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('Cookie settings') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'What this site stores on your device, and the one choice that is yours to make.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <section class="flex flex-col gap-2">
                <h3 class="text-sm font-semibold text-text">
                    {{ $t('Necessary') }}
                </h3>
                <p class="text-xs text-text-muted">
                    {{
                        $t(
                            'Required for the site to work at all; set without asking.',
                        )
                    }}
                </p>
                <ul class="flex flex-col gap-1 text-sm">
                    <li v-for="cookie in declared.necessary" :key="cookie.name">
                        <code class="rounded bg-subtle px-1 text-xs">{{
                            cookie.name
                        }}</code>
                        — {{ cookie.purpose }}
                        <span class="text-text-muted"
                            >({{ cookie.lifetime }})</span
                        >
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="cookie-functional"
                        :model-value="form.functional"
                        @update:model-value="
                            (value: boolean | 'indeterminate') =>
                                (form.functional = value === true)
                        "
                    />
                    <Label for="cookie-functional" class="font-semibold">{{
                        $t('Allow functional cookies')
                    }}</Label>
                </div>
                <p class="text-xs text-text-muted">
                    {{
                        $t(
                            'Preferences you set yourself, remembered beyond this visit. Turned off, they are kept only until you close the browser.',
                        )
                    }}
                </p>
                <ul class="flex flex-col gap-1 text-sm">
                    <li
                        v-for="cookie in declared.functional"
                        :key="cookie.name"
                    >
                        <code class="rounded bg-subtle px-1 text-xs">{{
                            cookie.name
                        }}</code>
                        — {{ cookie.purpose }}
                        <span class="text-text-muted"
                            >({{ cookie.lifetime }})</span
                        >
                    </li>
                </ul>
            </section>

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    @click="emit('close')"
                    >{{ $t('Close') }}</Button
                >
                <Button
                    type="button"
                    :disabled="form.processing"
                    @click="save"
                    >{{ $t('Save') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
