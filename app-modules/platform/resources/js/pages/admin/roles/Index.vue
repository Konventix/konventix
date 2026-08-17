<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ShieldMinus } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import {
    assign as assignRole,
    index as rolesIndex,
    revoke as revokeRole,
} from '@/routes/admin/roles';

/**
 * Per-brand role assignment (D-BATCH: Platform owns this screen; users are
 * referenced by ULID — copy one from the Users page). Member rows are bare
 * ULIDs until the planned identity read model adds display data.
 */
type RoleRow = {
    id: string;
    name: string;
    permissions: string[];
    members: string[];
};

const props = defineProps<{
    brands: { id: string; name: string }[];
    selectedBrandId: string | null;
    roles: RoleRow[];
}>();

function switchBrand(event: Event): void {
    const brand = (event.target as HTMLSelectElement).value;
    router.get(rolesIndex().url, { brand }, { preserveState: false });
}

const form = useForm({
    role_id: '',
    user_id: '',
});

function submitAssign(roleId: string): void {
    form.role_id = roleId;
    form.post(assignRole().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('user_id'),
    });
}

function submitRevoke(roleId: string, userId: string): void {
    router.post(
        revokeRole().url,
        { role_id: roleId, user_id: userId },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Roles" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Roles"
            description="Per-brand roles and who holds them"
        >
            <select
                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                :value="props.selectedBrandId ?? ''"
                @change="switchBrand"
            >
                <option
                    v-for="brand in props.brands"
                    :key="brand.id"
                    :value="brand.id"
                >
                    {{ brand.name }}
                </option>
            </select>
        </AdminPageHeader>

        <p v-if="props.roles.length === 0" class="text-sm text-text-muted">
            This brand has no roles yet — roles are created by
            <code class="rounded bg-subtle px-1">konventix:seed</code> or a
            future role editor.
        </p>

        <Card
            v-for="role in props.roles"
            :key="role.id"
            class="border-border bg-surface"
        >
            <CardHeader>
                <CardTitle class="flex items-baseline gap-3">
                    {{ role.name }}
                    <span class="text-xs font-normal text-text-muted">
                        {{ role.permissions.length }} capabilities
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="flex flex-wrap gap-1">
                    <span
                        v-for="permission in role.permissions"
                        :key="permission"
                        class="rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted"
                    >
                        {{ permission }}
                    </span>
                </div>

                <div class="flex flex-col gap-2">
                    <span
                        class="text-xs font-medium uppercase tracking-wide text-text-muted"
                    >
                        Members
                    </span>
                    <p
                        v-if="role.members.length === 0"
                        class="text-sm text-text-muted"
                    >
                        Nobody holds this role.
                    </p>
                    <div
                        v-for="member in role.members"
                        :key="member"
                        class="flex items-center gap-2"
                    >
                        <code class="rounded bg-subtle px-2 py-1 font-mono text-xs">
                            {{ member }}
                        </code>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="submitRevoke(role.id, member)"
                        >
                            <ShieldMinus class="size-4 text-danger" />
                        </Button>
                    </div>
                </div>

                <form
                    class="flex items-end gap-2"
                    @submit.prevent="submitAssign(role.id)"
                >
                    <div class="grid flex-1 gap-1">
                        <Label :for="`assign-${role.id}`" class="text-xs">
                            Assign by user ULID (copy from the Users page)
                        </Label>
                        <Input
                            :id="`assign-${role.id}`"
                            v-model="form.user_id"
                            placeholder="01k..."
                            class="font-mono"
                        />
                        <InputError
                            :message="
                                form.role_id === role.id
                                    ? form.errors.user_id
                                    : undefined
                            "
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="form.processing"
                    >
                        Assign
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
