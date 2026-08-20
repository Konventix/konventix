<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Palette, Trash2 } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update as updateBrand } from '@/routes/admin/brands';
import { update as updateModules } from '@/routes/admin/brands/modules';
import { edit as editTheme } from '@/routes/admin/brands/theme';

/**
 * Brand settings: identity fields, hosts, and per-brand module activation
 * (ADR-0003) — the Pennant state behind routes, navigation, UI slots and
 * listeners. Kernel and Core modules are always on and listed read-only so
 * the page tells the whole story.
 */
type Props = {
    brand: {
        id: string;
        name: string;
        slug: string;
        currency: string;
        hosts: string[];
    };
    modules: { module: string; active: boolean }[];
    alwaysOn: string[];
};

const props = defineProps<Props>();

const form = useForm({
    name: props.brand.name,
    slug: props.brand.slug,
    currency: props.brand.currency,
    hosts: [...props.brand.hosts],
});

const modulesForm = useForm({
    modules: Object.fromEntries(
        props.modules.map((entry) => [entry.module, entry.active]),
    ) as Record<string, boolean>,
});

useUnsavedChanges(() => form.isDirty || modulesForm.isDirty);

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

function moduleLabel(module: string): string {
    return module
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}
</script>

<template>
    <Head :title="brand.name" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="brand.name"
            description="Identity, hosts and module activation for this brand"
        >
            <Button as-child variant="outline">
                <Link :href="editTheme(brand.id)">
                    <Palette class="size-4" />
                    Theme editor
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitDetails"
                    >
                        <div class="grid gap-2">
                            <Label for="brand-name">Name</Label>
                            <Input id="brand-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="brand-slug">Slug</Label>
                                <Input id="brand-slug" v-model="form.slug" />
                                <InputError :message="form.errors.slug" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="brand-currency">Currency</Label>
                                <Input
                                    id="brand-currency"
                                    v-model="form.currency"
                                    maxlength="3"
                                    placeholder="EUR"
                                />
                                <InputError :message="form.errors.currency" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label>Hosts</Label>
                            <p class="text-xs text-text-muted">
                                The first host is the primary. Removing a host
                                stops serving the brand there immediately.
                            </p>
                            <div
                                v-for="(_, index) in form.hosts"
                                :key="index"
                                class="flex gap-2"
                            >
                                <Input
                                    v-model="form.hosts[index]"
                                    placeholder="brand.example"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :disabled="form.hosts.length <= 1"
                                    aria-label="Remove host"
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
                                Add host
                            </Button>
                            <InputError :message="form.errors.hosts" />
                        </div>
                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing">
                                Save details
                            </Button>
                            <span
                                v-if="form.isDirty"
                                role="status"
                                class="text-sm text-warning"
                            >
                                Unsaved changes
                            </span>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Modules</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitModules"
                    >
                        <p class="text-sm text-text-muted">
                            What this brand offers. Switching a module off hides
                            its routes, navigation and UI contributions for this
                            brand only — the data stays.
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
                                Always on
                            </span>
                            <p class="text-xs text-text-muted">
                                {{ alwaysOn.map(moduleLabel).join(', ') }} —
                                kernel and core modules cannot be switched off.
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="modulesForm.processing"
                            >
                                Save modules
                            </Button>
                            <span
                                v-if="modulesForm.isDirty"
                                role="status"
                                class="text-sm text-warning"
                            >
                                Unsaved changes
                            </span>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
