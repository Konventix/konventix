<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update as updateRole } from '@/routes/admin/roles';

/**
 * One per-brand role and its capability grants (ADR-0013): the catalogue
 * is discovered from every module's Permission enum — a capability appears
 * here by declaration alone, grouped by its module prefix.
 */
type Props = {
    role: {
        id: string;
        name: string;
        brandId: string;
        brandName: string;
        defaultKey: string | null;
        customized: boolean;
    };
    granted: string[];
    catalog: { module: string; permissions: string[] }[];
};

const props = defineProps<Props>();

const form = useForm({
    name: props.role.name,
    permissions: [...props.granted],
});

useUnsavedChanges(() => form.isDirty);

function isGranted(permission: string): boolean {
    return form.permissions.includes(permission);
}

function toggle(permission: string, value: boolean): void {
    if (value && !isGranted(permission)) {
        form.permissions.push(permission);
    } else if (!value) {
        form.permissions = form.permissions.filter(
            (name) => name !== permission,
        );
    }
}

const defaultModule = props.role.defaultKey?.split('.')[0] ?? null;

function moduleLabel(module: string): string {
    return module.charAt(0).toUpperCase() + module.slice(1);
}

function shortLabel(permission: string): string {
    return permission.split('.', 2)[1] ?? permission;
}

function submit(): void {
    form.put(updateRole(props.role.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${role.name} — ${role.brandName}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="role.name"
            :description="`Role of ${role.brandName} — ${form.permissions.length} capabilities granted`"
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Name</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid max-w-md gap-2">
                        <Label for="role-name">Role name</Label>
                        <Input id="role-name" v-model="form.name" />
                        <InputError :message="form.errors.name" />
                        <p
                            v-if="
                                props.role.defaultKey && !props.role.customized
                            "
                            class="text-xs text-text-muted"
                        >
                            This is the {{ defaultModule }} module's default
                            role. Once you save an edit, permissions:sync will
                            not change it again.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Capabilities</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-6">
                    <InputError :message="form.errors.permissions" />
                    <fieldset
                        v-for="group in catalog"
                        :key="group.module"
                        class="flex flex-col gap-2"
                    >
                        <legend class="text-sm font-medium text-text">
                            {{ moduleLabel(group.module) }}
                        </legend>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            <div
                                v-for="permission in group.permissions"
                                :key="permission"
                                class="flex items-center gap-2"
                            >
                                <Checkbox
                                    :id="`perm-${permission}`"
                                    :model-value="isGranted(permission)"
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') =>
                                            toggle(permission, value === true)
                                    "
                                />
                                <Label
                                    :for="`perm-${permission}`"
                                    class="font-normal"
                                >
                                    {{ shortLabel(permission) }}
                                </Label>
                            </div>
                        </div>
                    </fieldset>
                </CardContent>
            </Card>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    Save role
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
    </div>
</template>
