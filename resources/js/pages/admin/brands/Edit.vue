<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Palette, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import MediaPicker from '@/components/admin/MediaPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminCapabilities } from '@/composables/useAdminCapabilities';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { update as updateBrand } from '@/routes/admin/brands';
import { update as updateFeatures } from '@/routes/admin/brands/features';
import { update as updateModules } from '@/routes/admin/brands/modules';
import { edit as editTheme } from '@/routes/admin/brands/theme';

/**
 * Brand settings: identity fields, hosts, per-brand module activation
 * (ADR-0003), and per-brand features with no module behind them (ADR-0047)
 * — the Pennant state behind routes, navigation, UI slots and listeners.
 * Kernel and Core modules are always on and listed read-only so the page
 * tells the whole story.
 */
type Props = {
    brand: {
        id: string;
        name: string;
        slug: string;
        currency: string;
        hosts: string[];
        logoMediaId: string | null;
        logoDarkMediaId: string | null;
        faviconMediaId: string | null;
    };
    /** This brand's images, offered to the asset pickers (ADR-0035). */
    media: Konventix.Platform.Data.MediaItemData[];
    modules: { module: string; active: boolean }[];
    alwaysOn: string[];
    features: Konventix.Platform.Data.BrandFeatureData[];
};

const props = defineProps<Props>();
const { canManageMedia } = useAdminCapabilities();

const form = useForm({
    name: props.brand.name,
    slug: props.brand.slug,
    currency: props.brand.currency,
    hosts: [...props.brand.hosts],
    logoMediaId: props.brand.logoMediaId,
    logoDarkMediaId: props.brand.logoDarkMediaId,
    faviconMediaId: props.brand.faviconMediaId,
});

const modulesForm = useForm({
    modules: Object.fromEntries(
        props.modules.map((entry) => [entry.module, entry.active]),
    ) as Record<string, boolean>,
});

const featuresForm = useForm({
    features: Object.fromEntries(
        props.features.map((entry) => [entry.name, entry.active]),
    ) as Record<string, boolean>,
});

useUnsavedChanges(
    () => form.isDirty || modulesForm.isDirty || featuresForm.isDirty,
);

function submitDetails(): void {
    form.transform((data) => ({
        ...data,
        hosts: data.hosts.filter((host) => host.trim() !== ''),
    })).put(updateBrand(props.brand.id).url, { preserveScroll: true });
}

function submitModules(): void {
    modulesForm.put(updateModules(props.brand.id).url, {
        preserveScroll: true,
    });
}

function submitFeatures(): void {
    featuresForm.put(updateFeatures(props.brand.id).url, {
        preserveScroll: true,
    });
}

function moduleLabel(module: string): string {
    return module
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

/** The always-on modules as one list, for the "cannot be switched off" line. */
const alwaysOnModuleNames = computed(() =>
    props.alwaysOn.map(moduleLabel).join(', '),
);
</script>

<template>
    <Head :title="brand.name" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="brand.name"
            :description="
                $t('Identity, hosts and module activation for this brand')
            "
        >
            <Button as-child variant="outline">
                <Link :href="editTheme(brand.id)">
                    <Palette class="size-4" />
                    {{ $t('Theme editor') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Details') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitDetails"
                    >
                        <div class="grid gap-2">
                            <Label for="brand-name">{{ $t('Name') }}</Label>
                            <Input id="brand-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="brand-slug">{{ $t('Slug') }}</Label>
                                <Input id="brand-slug" v-model="form.slug" />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="brand-currency">{{
                                    $t('Currency')
                                }}</Label>
                                <Input
                                    id="brand-currency"
                                    v-model="form.currency"
                                    maxlength="3"
                                    :placeholder="'EUR'"
                                />
                                <InputError :message="form.errors.currency" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('Hosts') }}</Label>
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'The first host is the primary. Removing a host stops serving the brand there immediately.',
                                    )
                                }}
                            </p>
                            <div
                                v-for="(_, index) in form.hosts"
                                :key="index"
                                class="flex gap-2"
                            >
                                <Input
                                    v-model="form.hosts[index]"
                                    :placeholder="'brand.example'"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="form.hosts.length <= 1"
                                    :aria-label="$t('Remove host')"
                                    @click="form.hosts.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                class="self-start"
                                @click="form.hosts.push('')"
                            >
                                {{ $t('Add host') }}
                            </Button>
                            <InputError :message="form.errors.hosts" />
                        </div>
                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing">
                                {{ $t('Save details') }}
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

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Appearance') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-6"
                        @submit.prevent="submitDetails"
                    >
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    "The mark in this brand's topbar and the icon in the browser tab. Anything left unset falls back to the bundled Konventix artwork. Files come from this brand's media library.",
                                )
                            }}
                        </p>

                        <MediaPicker
                            :brand-id="props.brand.id"
                            :can-upload="canManageMedia"
                            v-model="form.logoMediaId"
                            :label="$t('Logo (light mode)')"
                            :media="media"
                        />
                        <InputError :message="form.errors.logoMediaId" />

                        <MediaPicker
                            :brand-id="props.brand.id"
                            :can-upload="canManageMedia"
                            v-model="form.logoDarkMediaId"
                            :label="$t('Logo (dark mode)')"
                            :media="media"
                            described-by="logo-dark-hint"
                        />
                        <p
                            id="logo-dark-hint"
                            class="-mt-4 text-xs text-text-muted"
                        >
                            {{
                                $t(
                                    'Optional. Without one, the light mark is used in both themes.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.logoDarkMediaId" />

                        <MediaPicker
                            :brand-id="props.brand.id"
                            :can-upload="canManageMedia"
                            v-model="form.faviconMediaId"
                            :label="$t('Favicon')"
                            :media="media"
                            described-by="favicon-hint"
                        />
                        <p
                            id="favicon-hint"
                            class="-mt-4 text-xs text-text-muted"
                        >
                            {{ $t('A square PNG or SVG.') }}
                        </p>
                        <InputError :message="form.errors.faviconMediaId" />

                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing">
                                {{ $t('Save appearance') }}
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

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Modules') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitModules"
                    >
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    'What this brand offers. Switching a module off hides its routes, navigation and UI contributions for this brand only — the data stays.',
                                )
                            }}
                        </p>

                        <div class="flex flex-col gap-3">
                            <div
                                v-for="entry in props.modules"
                                :key="entry.module"
                                class="flex items-center gap-2"
                            >
                                <Checkbox
                                    :id="`module-${entry.module}`"
                                    :model-value="
                                        modulesForm.modules[entry.module]
                                    "
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') => {
                                            modulesForm.modules[entry.module] =
                                                value === true;
                                        }
                                    "
                                />
                                <Label :for="`module-${entry.module}`">
                                    {{ moduleLabel(entry.module) }}
                                </Label>
                            </div>
                        </div>

                        <div v-if="alwaysOn.length" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-text-muted">
                                {{ $t('Always on') }}
                            </span>
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        ':modules — kernel and core modules cannot be switched off.',
                                        { modules: alwaysOnModuleNames },
                                    )
                                }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="modulesForm.processing"
                            >
                                {{ $t('Save modules') }}
                            </Button>
                            <span
                                v-if="modulesForm.isDirty"
                                role="status"
                                class="text-sm text-warning"
                            >
                                {{ $t('Unsaved changes') }}
                            </span>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Features') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitFeatures"
                    >
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    'Per-brand features with no module behind them (ADR-0047) — switching one off hides its routes, navigation and UI contributions for this brand only, the data stays.',
                                )
                            }}
                        </p>

                        <div class="flex flex-col gap-3">
                            <div
                                v-for="entry in props.features"
                                :key="entry.name"
                                class="flex items-start gap-2"
                            >
                                <Checkbox
                                    :id="`feature-${entry.name}`"
                                    :model-value="
                                        featuresForm.features[entry.name]
                                    "
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') => {
                                            featuresForm.features[entry.name] =
                                                value === true;
                                        }
                                    "
                                />
                                <div class="flex flex-col gap-0.5">
                                    <Label :for="`feature-${entry.name}`">
                                        {{ entry.label }}
                                    </Label>
                                    <p class="text-xs text-text-muted">
                                        {{ entry.description }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="featuresForm.processing"
                            >
                                {{ $t('Save features') }}
                            </Button>
                            <span
                                v-if="featuresForm.isDirty"
                                role="status"
                                class="text-sm text-warning"
                            >
                                {{ $t('Unsaved changes') }}
                            </span>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!--
                A feature's own settings card (ADR-0047 point 5) — declared
                by the module through `BrandFeature`'s `settingsComponent`,
                resolved by registered name so this host page never imports
                a module's component. Renders only for a feature the
                backend already reports `active` and carrying one; each
                card owns its own form and its own ADR-0038 dirty guard,
                the same way every card above does.
            -->
            <template
                v-for="entry in props.features"
                :key="`settings-${entry.name}`"
            >
                <component
                    :is="
                        resolveRegisteredComponent(
                            entry.settingsComponent ?? '',
                        )
                    "
                    v-if="
                        entry.active &&
                        entry.settingsComponent &&
                        resolveRegisteredComponent(entry.settingsComponent)
                    "
                    v-bind="entry.settingsProps ?? {}"
                />
            </template>
        </div>
    </div>
</template>
