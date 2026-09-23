<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update } from '@/routes/admin/brands/waiting-room';

/**
 * Commerce's own contribution to the brand edit page (ADR-0047 point 5) —
 * rendered only while `commerce:waiting-room` is active for this brand,
 * via `BrandFeature`'s `settingsComponent`
 * (`CommerceServiceProvider::registerBrandFeatures()`), never a direct
 * import: the host page resolves it by registered name, the same way
 * `event.landing`/`admin.dashboard` slot contributions are.
 *
 * Its own `useForm` and its own ADR-0038 dirty guard, independent of the
 * brand edit page's other cards — each of those owns its own the same way.
 */
type Settings = Konventix.Commerce.Data.WaitingRoomSettingsData;

const props = defineProps<{
    brandId: string;
    settings: Settings;
}>();

const form = useForm({
    admitPerInterval: props.settings.admitPerInterval,
    admissionTtlMinutes: props.settings.admissionTtlMinutes,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(update(props.brandId).url, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ $t('Waiting room') }}</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <p class="text-sm text-text-muted">
                    {{
                        $t(
                            'How many arrivals are admitted at once, and how long an admitted ticket keeps its place before it must queue again.',
                        )
                    }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="waiting-room-admit-per-interval">
                            {{ $t('Admitted per batch') }}
                        </Label>
                        <Input
                            id="waiting-room-admit-per-interval"
                            v-model.number="form.admitPerInterval"
                            type="number"
                            min="1"
                            max="1000"
                        />
                        <InputError :message="form.errors.admitPerInterval" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="waiting-room-admission-ttl">
                            {{ $t('Admission window (minutes)') }}
                        </Label>
                        <Input
                            id="waiting-room-admission-ttl"
                            v-model.number="form.admissionTtlMinutes"
                            type="number"
                            min="1"
                            max="240"
                        />
                        <InputError
                            :message="form.errors.admissionTtlMinutes"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="form.processing"
                    >
                        {{ $t('Save waiting room') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
