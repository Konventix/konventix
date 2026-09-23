<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, ShieldMinus } from '@lucide/vue';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    assign as assignRole,
    edit as editRole,
    index as rolesIndex,
    revoke as revokeRole,
    store as storeRole,
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
    defaultKey: string | null;
    customized: boolean;
};

const props = defineProps<{
    brands: { id: string; name: string }[];
    selectedBrandId: string | null;
    roles: RoleRow[];
}>();

/**
 * The brand this page is looking at. Not a filter — roles only exist per
 * brand — so it defaults to the console selection server-side and there is
 * no "all brands" option to choose.
 */
function switchBrand(value: unknown): void {
    router.get(
        rolesIndex().url,
        { brand: String(value) },
        { preserveState: false },
    );
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

const createOpen = ref(false);

const createForm = useForm({
    brand_id: props.selectedBrandId ?? '',
    name: '',
});

function submitCreate(): void {
    createForm.brand_id = props.selectedBrandId ?? '';
    createForm.post(storeRole().url, {
        onSuccess: () => {
            createForm.reset('name');
            createOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="$t('Roles')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Roles')"
            :description="$t('Per-brand roles and who holds them')"
        >
            <label for="roles-brand" class="sr-only">{{ $t('Brand') }}</label>
            <Select
                :model-value="props.selectedBrandId ?? undefined"
                @update:model-value="switchBrand"
            >
                <SelectTrigger id="roles-brand" class="w-56">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="brand in props.brands"
                        :key="brand.id"
                        :value="brand.id"
                    >
                        {{ brand.name }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Dialog v-model:open="createOpen">
                <DialogTrigger as-child>
                    <Button>
                        <Plus class="size-4" />
                        {{ $t('New role') }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{{ $t('Create role') }}</DialogTitle>
                    </DialogHeader>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitCreate"
                    >
                        <div class="grid gap-2">
                            <Label for="new-role-name">{{ $t('Name') }}</Label>
                            <Input
                                id="new-role-name"
                                v-model="createForm.name"
                            />
                            <InputError :message="createForm.errors.name" />
                        </div>
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'Created for the selected brand — you grant its capabilities on the next screen.',
                                )
                            }}
                        </p>
                        <Button type="submit" :disabled="createForm.processing">
                            {{ $t('Create') }}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminPageHeader>

        <p v-if="props.roles.length === 0" class="text-sm text-text-muted">
            {{
                $t(
                    'This brand has no roles yet — create your own with “New role”. Default roles arrive with',
                )
            }}
            <code class="rounded bg-subtle px-1">permissions:sync</code>.
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
                        {{
                            $tChoice(
                                '{1} :count capability|[0,*] :count capabilities',
                                role.permissions.length,
                            )
                        }}
                    </span>
                    <span
                        v-if="role.defaultKey"
                        class="rounded-full bg-subtle px-2 py-0.5 text-xs font-normal text-text-muted"
                        :title="
                            $t('Shipped by the :module module', {
                                module: role.defaultKey.split('.')[0],
                            })
                        "
                    >
                        {{ $t('default') }} ·
                        {{ role.defaultKey.split('.')[0] }}
                        <template v-if="role.customized">
                            · {{ $t('customized') }}</template
                        >
                    </span>
                    <Button as-child variant="ghost" size="sm" class="ml-auto">
                        <Link :href="editRole(role.id)">
                            <Pencil class="size-4" />
                            {{ $t('Edit') }}
                        </Link>
                    </Button>
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
                        class="text-xs font-medium tracking-wide text-text-muted uppercase"
                    >
                        {{ $t('Members') }}
                    </span>
                    <p
                        v-if="role.members.length === 0"
                        class="text-sm text-text-muted"
                    >
                        {{ $t('Nobody holds this role.') }}
                    </p>
                    <div
                        v-for="member in role.members"
                        :key="member"
                        class="flex items-center gap-2"
                    >
                        <code
                            class="rounded bg-subtle px-2 py-1 font-mono text-xs"
                        >
                            {{ member }}
                        </code>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="submitRevoke(role.id, member)"
                        >
                            <ShieldMinus class="size-4 text-danger" />
                            <span class="sr-only">{{
                                $t('Revoke role from :user', { user: member })
                            }}</span>
                        </Button>
                    </div>
                </div>

                <form
                    class="flex items-end gap-2"
                    @submit.prevent="submitAssign(role.id)"
                >
                    <div class="grid flex-1 gap-1">
                        <Label :for="`assign-${role.id}`" class="text-xs">
                            {{
                                $t(
                                    'Assign by user ULID (copy from the Users page)',
                                )
                            }}
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
                        {{ $t('Assign') }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
