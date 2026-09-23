<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Lock, LockOpen } from '@lucide/vue';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { index as registrationsForAddress } from '@/routes/admin/registrations';
import {
    edit as editUser,
    lock as lockUser,
    merge as mergeUser,
    unlock as unlockUser,
    update as updateUser,
} from '@/routes/admin/users';
import { accountStateLabel } from './columns';

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
        /** ADR-0068 §4: null follows the brand's language. */
        locale: string | null;
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
    // '' is "Default" — a <select> has no null, and `UpdateUserAccount`
    // stores it as null again.
    locale: props.user.locale ?? '',
    timezone: props.user.timezone ?? '',
});

const page = usePage();

const lockForm = useForm({ reason: '' });
const mergeForm = useForm({
    email: '',
    acknowledgedClosedAccountHistoryStaysBehind: false,
});

useUnsavedChanges(() => form.isDirty);

/**
 * A quick, honest check before an irreversible merge (correction on #81):
 * Registration's admin list is the one module with a live search by email
 * already (`RegistrationAdminController::index()`'s free-text `search`).
 * Commerce and Admission have no equivalent admin search yet, so this link
 * covers registrations only — it is a partial check, not a full "what does
 * this account hold" view, and nothing here claims otherwise.
 */
const registrationsForEmailHref = computed(() =>
    registrationsForAddress.url({ query: { search: mergeForm.email } }),
);

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
            :description="
                $t('Account since :date', {
                    date: user.createdAt ?? $t('unknown'),
                })
            "
        >
            <Badge
                :variant="
                    user.state === 'locked' || user.state === 'merged'
                        ? 'destructive'
                        : 'secondary'
                "
            >
                {{ accountStateLabel(user.state) }}
            </Badge>
            <Badge v-if="user.verified" variant="secondary">{{
                $t('Email verified')
            }}</Badge>
            <Badge v-if="user.twoFactor" variant="secondary">{{ '2FA' }}</Badge>
        </AdminPageHeader>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Profile') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="user-name">{{ $t('Name') }}</Label>
                            <Input id="user-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="user-email">{{ $t('Email') }}</Label>
                            <Input
                                id="user-email"
                                v-model="form.email"
                                type="email"
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Changing the address un-verifies it — the person confirms the new one on next login.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="user-locale">{{
                                    $t('Language')
                                }}</Label>
                                <select
                                    id="user-locale"
                                    v-model="form.locale"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option value="">
                                        {{ $t('Default (follows the brand)') }}
                                    </option>
                                    <option
                                        v-for="option in page.props.locales"
                                        :key="option.code"
                                        :value="option.code"
                                    >
                                        {{ option.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.locale" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="user-timezone">{{
                                    $t('Timezone')
                                }}</Label>
                                <Input
                                    id="user-timezone"
                                    v-model="form.timezone"
                                    :placeholder="'Europe/Berlin'"
                                    aria-describedby="user-timezone-hint user-timezone-error"
                                />
                                <p
                                    id="user-timezone-hint"
                                    class="text-xs text-text-muted"
                                >
                                    {{
                                        $t(
                                            "Leave empty to show this account every timestamp on its own browser's clock.",
                                        )
                                    }}
                                </p>
                                <InputError
                                    id="user-timezone-error"
                                    :message="form.errors.timezone"
                                />
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing">
                                {{ $t('Save account') }}
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

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Account state') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <template v-if="user.state === 'merged'">
                            <p class="text-sm text-text-muted">
                                {{
                                    $t(
                                        'This account was merged into another one — signing in with it no longer works.',
                                    )
                                }}
                            </p>
                            <Button
                                v-if="user.mergedInto"
                                as-child
                                variant="outline"
                                class="self-start"
                            >
                                <a :href="editUser(user.mergedInto.id).url">
                                    {{
                                        $t('Go to :email', {
                                            email: user.mergedInto.email,
                                        })
                                    }}
                                </a>
                            </Button>
                        </template>
                        <template v-else-if="user.state !== 'locked'">
                            <p class="text-sm text-text-muted">
                                {{
                                    $t(
                                        'Locking refuses the next login attempt. Existing sessions are not revoked here.',
                                    )
                                }}
                            </p>
                            <form
                                class="flex flex-col gap-3"
                                @submit.prevent="submitLock"
                            >
                                <div class="grid gap-2">
                                    <Label for="lock-reason">{{
                                        $t('Reason')
                                    }}</Label>
                                    <Input
                                        id="lock-reason"
                                        v-model="lockForm.reason"
                                        :placeholder="
                                            $t(
                                                'Why this account is being locked',
                                            )
                                        "
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
                                    {{ $t('Lock account') }}
                                </Button>
                            </form>
                        </template>
                        <template v-else>
                            <p class="text-sm text-text-muted">
                                {{
                                    $t(
                                        'This account is locked — logins are refused.',
                                    )
                                }}
                            </p>
                            <Button
                                variant="outline"
                                class="self-start"
                                :disabled="lockForm.processing"
                                @click="submitUnlock"
                            >
                                <LockOpen class="size-4" />
                                {{ $t('Unlock account') }}
                            </Button>
                        </template>
                    </CardContent>
                </Card>

                <Card v-if="user.state !== 'merged'">
                    <CardHeader>
                        <CardTitle>{{ $t('Merge accounts') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    "Combines a duplicate account's Steam or password sign-in into this one — the shape most migrated accounts hit if they sign in with Steam before being recognised. This account is kept; the other one closes and can no longer sign in.",
                                )
                            }}
                        </p>
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    'Only sign-in methods move. Brand memberships and roles are not moved either. Only run this after the person has asked for it, you have confirmed they control both addresses, and you are sure which account should survive — this cannot be undone from here.',
                                )
                            }}
                        </p>
                        <form
                            class="flex flex-col gap-3"
                            @submit.prevent="submitMerge"
                        >
                            <div class="grid gap-2">
                                <Label for="merge-email">
                                    {{ $t("Other account's email") }}
                                </Label>
                                <Input
                                    id="merge-email"
                                    v-model="mergeForm.email"
                                    type="email"
                                    :placeholder="'other-account@example.test'"
                                />
                                <InputError :message="mergeForm.errors.email" />
                                <a
                                    v-if="mergeForm.email"
                                    :href="registrationsForEmailHref"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-xs text-accent underline underline-offset-2"
                                >
                                    {{
                                        $t(
                                            "Check this address's registrations before merging",
                                        )
                                    }}
                                </a>
                            </div>
                            <div class="flex items-start gap-2">
                                <Checkbox
                                    id="merge-acknowledge"
                                    class="mt-0.5"
                                    aria-describedby="merge-acknowledge-error"
                                    :model-value="
                                        mergeForm.acknowledgedClosedAccountHistoryStaysBehind
                                    "
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') =>
                                            (mergeForm.acknowledgedClosedAccountHistoryStaysBehind =
                                                value === true)
                                    "
                                />
                                <Label
                                    for="merge-acknowledge"
                                    class="text-sm font-normal"
                                >
                                    {{
                                        $t(
                                            "I understand the closed account's registrations, orders, admissions and consent records will not move — they'll stay behind and become unreachable.",
                                        )
                                    }}
                                </Label>
                            </div>
                            <InputError
                                id="merge-acknowledge-error"
                                :message="
                                    mergeForm.errors
                                        .acknowledgedClosedAccountHistoryStaysBehind
                                "
                            />
                            <Button
                                type="submit"
                                variant="destructive"
                                class="self-start"
                                :disabled="
                                    mergeForm.processing ||
                                    !mergeForm.acknowledgedClosedAccountHistoryStaysBehind
                                "
                            >
                                {{ $t('Merge into this account') }}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="mergedAccounts.length > 0">
                    <CardHeader>
                        <CardTitle>{{
                            $t('Accounts merged into this one')
                        }}</CardTitle>
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
                        <CardTitle>{{ $t('Brands & roles') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <span class="text-xs font-medium text-text-muted">{{
                                $t('Member of')
                            }}</span>
                            <p
                                v-if="memberships.length === 0"
                                class="text-sm text-text-muted"
                            >
                                {{ $t('No brand memberships recorded yet.') }}
                            </p>
                            <ul v-else class="mt-1 flex flex-col gap-1 text-sm">
                                <li
                                    v-for="membership in memberships"
                                    :key="membership.brand"
                                    class="flex justify-between"
                                >
                                    <span>{{ membership.brand }}</span>
                                    <span class="text-text-muted">
                                        {{
                                            $t('since :date', {
                                                date: formatCalendarDate(
                                                    membership.firstSeenAt,
                                                ),
                                            })
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <span class="text-xs font-medium text-text-muted">{{
                                $t('Roles')
                            }}</span>
                            <p
                                v-if="roles.length === 0"
                                class="text-sm text-text-muted"
                            >
                                {{
                                    $t(
                                        'No roles — assign them on the Roles page.',
                                    )
                                }}
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
