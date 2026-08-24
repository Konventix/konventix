<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleUser, LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { index as settingsIndex } from '@/routes/settings';
import type { User } from '@/types';

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
    user: User;
    variant?: 'menu' | 'plain';
};

const { variant = 'menu' } = defineProps<Props>();

const emit = defineEmits<{ logout: [] }>();

const entries = [
    { label: 'Account', href: editProfile(), icon: CircleUser },
    { label: 'Settings', href: settingsIndex(), icon: Settings },
];
</script>

<template>
    <template v-if="variant === 'menu'">
        <DropdownMenuLabel class="p-0 font-normal">
            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                <UserInfo :user="user" :show-email="true" />
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
                Log out
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
            Log out
        </Link>
    </template>
</template>
