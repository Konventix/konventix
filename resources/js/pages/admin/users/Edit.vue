<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, LockOpen } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import {
    edit as editUser,
    lock as lockUser,
    merge as mergeUser,
    unlock as unlockUser,
    update as updateUser,
} from '@/routes/admin/users';

/**
 * One account, operator-wide (ADR-0016): profile fields, account state
 * with lock/unlock, and the brands and roles this person touches. A lock
 * takes effect at the next login attempt — sessions are not revoked here.
 *
 * `mergedInto`/`mergedAccounts` are #81's account-merge trail: which
 * account absorbed this one, if any, and which accounts this one has
 * absorbed. Merging only ever combines login methods — see the "Merge
 * accounts" card's own copy for what it does not move, in the same words
 * `Actions\MergeUserAccounts`'s docblock uses.
 */
type Props = {
    user: {
        id: string;
        name: string;
        email: string;
        locale: string;
        timezone: string | null;
        state: string;
        verified: boolean;
        twoFactor: boolean;
        createdAt: string | null;
        mergedInto: { id: string; email: string } | null;
    };
    memberships: { brand: string; firstSeenAt: string }[];
    roles: { name: string; brand: string }[];
    mergedAccounts: { id: string; email: string; mergedAt: string | null }[];
};

const props = defineProps<Props>();

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    locale: props.user.locale,
    timezone: props.user.timezone ?? '',
});

const lockForm = useForm({ reason: '' });
const mergeForm = useForm({ email: '' });

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(updateUser(props.user.id).url, { preserveScroll: true });
}

function submitLock(): void {
    lockForm.post(lockUser(props.user.id).url, {
        preserveScroll: true,
        onSuccess: () => lockForm.reset(),
    });
}

function submitUnlock(): void {
    lockForm.post(unlockUser(props.user.id).url, { preserveScroll: true });
}

function submitMerge(): void {
    mergeForm.post(mergeUser(props.user.id).url, {
        preserveScroll: true,
        onSuccess: () => mergeForm.reset(),
    });
}
</script>

<template>
    <Head :title="user.name" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="user.name"
            :description="`Account since ${user.createdAt ?? 'unknown'}`"
        >
            <Badge
                :variant="
                    user.state === 'locked' || user.state === 'merged'
                        ? 'destructive'
                        : 'secondary'
                "
            >
                {{ user.state }}
            </Badge>
            <Badge v-if="user.verified" variant="secondary">Verified</Badge>
            <Badge v-if="user.twoFactor" variant="secondary">2FA</Badge>
        </AdminPageHeader>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Profile</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="user-name">Name</Label>
                            <Input id="user-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="user-email">Email</Label>
                            <Input
                                id="user-email"
                                v-model="form.email"
                                type="email"
                            />
                            <p class="text-xs text-text-muted">
                                Changing the address un-verifies it — the person
                                confirms the new one on next login.
                            </p>
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="user-locale">Locale</Label>
                                <Input
                                    id="user-locale"
                                    v-model="form.locale"
                                    placeholder="de"
                                />
                                <InputError :message="form.errors.locale" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="user-timezone">Timezone</Label>
                                <Input
                                    id="user-timezone"
                                    v-model="form.timezone"
                                    placeholder="Europe/Berlin"
                                    aria-describedby="user-timezone-hint user-timezone-error"
                                />
                                <p
                                    id="user-timezone-hint"
                                    class="text-xs text-text-muted"
                                >
                                    Leave empty to show this account every
                                    timestamp on its own browser's clock.
                                </p>
                                <InputError
                                    id="user-timezone-error"
                                    :message="form.errors.timezone"
                                />
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing">
                                Save account
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

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Account state</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <template v-if="user.state === 'merged'">
                            <p class="text-sm text-text-muted">
                                This account was merged into another one —
                                signing in with it no longer works.
                            </p>
                            <Button
                                v-if="user.mergedInto"
                                as-child
                                variant="outline"
                                class="self-start"
                            >
                                <a :href="editUser(user.mergedInto.id).url">
                                    Go to {{ user.mergedInto.email }}
                                </a>
                            </Button>
                        </template>
                        <template v-else-if="user.state !== 'locked'">
                            <p class="text-sm text-text-muted">
                                Locking refuses the next login attempt. Existing
                                sessions are not revoked here.
                            </p>
                            <form
                                class="flex flex-col gap-3"
                                @submit.prevent="submitLock"
                            >
                                <div class="grid gap-2">
                                    <Label for="lock-reason">Reason</Label>
                                    <Input
                                        id="lock-reason"
                                        v-model="lockForm.reason"
                                        placeholder="Why this account is being locked"
                                    />
                                    <InputError
                                        :message="lockForm.errors.reason"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    class="self-start"
                                    :disabled="lockForm.processing"
                                >
                                    <Lock class="size-4" />
                                    Lock account
                                </Button>
                            </form>
                        </template>
                        <template v-else>
                            <p class="text-sm text-text-muted">
                                This account is locked — logins are refused.
                            </p>
                            <Button
                                variant="outline"
                                class="self-start"
                                :disabled="lockForm.processing"
                                @click="submitUnlock"
                            >
                                <LockOpen class="size-4" />
                                Unlock account
                            </Button>
                        </template>
                    </CardContent>
                </Card>

                <Card v-if="user.state !== 'merged'">
                    <CardHeader>
                        <CardTitle>Merge accounts</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            Combines a duplicate account's Steam or password
                            sign-in into this one — the shape most migrated
                            accounts hit if they sign in with Steam before being
                            recognised. This account is kept; the other one
                            closes and can no longer sign in.
                        </p>
                        <p class="text-sm text-text-muted">
                            Only sign-in methods move. Registrations, orders,
                            admissions and consent records stay on the closed
                            account until each of those modules ships a listener
                            for this event — ask the person to contact support
                            if they need that history moved too. Brand
                            memberships and roles are not moved either. Only run
                            this after the person has asked for it and you have
                            confirmed they control both addresses.
                        </p>
                        <form
                            class="flex flex-col gap-3"
                            @submit.prevent="submitMerge"
                        >
                            <div class="grid gap-2">
                                <Label for="merge-email">
                                    Other account's email
                                </Label>
                                <Input
                                    id="merge-email"
                                    v-model="mergeForm.email"
                                    type="email"
                                    placeholder="other-account@example.test"
                                />
                                <InputError :message="mergeForm.errors.email" />
                            </div>
                            <Button
                                type="submit"
                                variant="destructive"
                                class="self-start"
                                :disabled="mergeForm.processing"
                            >
                                Merge into this account
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="mergedAccounts.length > 0">
                    <CardHeader>
                        <CardTitle>Accounts merged into this one</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="flex flex-col gap-1 text-sm">
                            <li
                                v-for="merged in mergedAccounts"
                                :key="merged.id"
                                class="flex justify-between"
                            >
                                <span>{{ merged.email }}</span>
                                <span
                                    v-if="merged.mergedAt"
                                    class="text-text-muted"
                                >
                                    {{ formatDateTime(merged.mergedAt) }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Brands & roles</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <span class="text-xs font-medium text-text-muted"
                                >Member of</span
                            >
                            <p
                                v-if="memberships.length === 0"
                                class="text-sm text-text-muted"
                            >
                                No brand memberships recorded yet.
                            </p>
                            <ul v-else class="mt-1 flex flex-col gap-1 text-sm">
                                <li
                                    v-for="membership in memberships"
                                    :key="membership.brand"
                                    class="flex justify-between"
                                >
                                    <span>{{ membership.brand }}</span>
                                    <span class="text-text-muted">
                                        since
                                        {{
                                            formatCalendarDate(
                                                membership.firstSeenAt,
                                            )
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <span class="text-xs font-medium text-text-muted"
                                >Roles</span
                            >
                            <p
                                v-if="roles.length === 0"
                                class="text-sm text-text-muted"
                            >
                                No roles — assign them on the Roles page.
                            </p>
                            <div v-else class="mt-1 flex flex-wrap gap-2">
                                <Badge
                                    v-for="role in roles"
                                    :key="`${role.brand}-${role.name}`"
                                    variant="secondary"
                                >
                                    {{ role.name }} · {{ role.brand }}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
