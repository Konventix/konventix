<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CircleUser, LogOut, Settings } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { iconFor } from '@/lib/navIcons';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { index as settingsIndex } from '@/routes/settings';
import type { AuthenticatedUser } from '@/types';

/**
 * The account menu, in two presentations of one list.
 *
 * `menu` is the topbar and sidebar dropdown. `plain` is the mobile
 * navigation drawer, which is not a dropdown — Reka's menu items expect a
 * menu root and would neither style nor keyboard-handle correctly outside
 * one, so the same entries render as ordinary links there instead of being
 * duplicated into a second component.
 */
type Props = {
    user: AuthenticatedUser;
    variant?: 'menu' | 'plain';
};

const { variant = 'menu' } = defineProps<Props>();

const emit = defineEmits<{ logout: [] }>();

const page = usePage();

/**
 * Two static entries the host owns, and whatever the modules contributed to
 * `AREA_ACCOUNT` — "my registrations", "my orders". The module entries come
 * first: they are the reason someone opens this menu, and the profile and
 * settings forms are the housekeeping underneath them.
 *
 * They carry no capability of their own, and need none: this menu renders
 * only for a signed-in user, and each destination authorizes against that
 * user's own rows.
 */
const entries = computed(() => [
    ...(page.props.accountNav ?? []).map((item) => ({
        label: item.label,
        href: item.url,
        icon: iconFor(item.icon),
    })),
    { label: trans('Account'), href: editProfile(), icon: CircleUser },
    { label: trans('Settings'), href: settingsIndex(), icon: Settings },
]);
</script>

<template>
    <template v-if="variant === 'menu'">
        <DropdownMenuLabel class="p-0 font-normal">
            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                <UserInfo :user="user" />
            </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
            <DropdownMenuItem
                v-for="entry in entries"
                :key="entry.label"
                :as-child="true"
            >
                <Link
                    class="block w-full cursor-pointer"
                    :href="entry.href"
                    prefetch
                >
                    <component :is="entry.icon" class="mr-2 h-4 w-4" />
                    {{ entry.label }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="logout()"
                as="button"
                data-test="logout-button"
                @click="emit('logout')"
            >
                <LogOut class="mr-2 h-4 w-4" />
                {{ $t('Log out') }}
            </Link>
        </DropdownMenuItem>
    </template>

    <template v-else>
        <Link
            v-for="entry in entries"
            :key="entry.label"
            :href="entry.href"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-text-muted transition hover:bg-surface-muted hover:text-text"
        >
            <component :is="entry.icon" class="size-4" />
            {{ entry.label }}
        </Link>
        <Link
            :href="logout()"
            as="button"
            data-test="logout-button"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-text-muted transition hover:bg-surface-muted hover:text-text"
            @click="emit('logout')"
        >
            <LogOut class="size-4" />
            {{ $t('Log out') }}
        </Link>
    </template>
</template>
