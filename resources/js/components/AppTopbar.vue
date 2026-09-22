<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BrandMenuDesktop from '@/components/BrandMenuDesktop.vue';
import BrandMenuMobile from '@/components/BrandMenuMobile.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { livePillFor } from '@/lib/livePill';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { home, login, register } from '@/routes';
import type { BrandNavItem } from '@/types/navigation';

/**
 * The brand shell's one navigation bar, for logged-out visitors and members
 * alike (there is no second brand-facing chrome — the operator console keeps
 * its sidebar, ADR-0029).
 *
 * It holds no module imports. Navigation arrives as data from the shared
 * `nav` prop, which Platform's NavigationRegistry fills and filters by
 * module activation and capability (ADR-0003, enforcement point 2), and
 * the brand's own entries from `brandMenu` (Task 20), rendered after the
 * module entries and already resolved for this viewer on the server; the
 * announcement banner and the notification bell resolve through the module
 * component registry, so a build without Content or Messaging renders
 * neither rather than failing.
 *
 * The one exception is the cart badge (ADR-0047, Task 11): it keys off the
 * nav entry's own label rather than a module import, reading the count from
 * the shared `cart` prop `HandleInertiaRequests` already puts on every
 * response — a build without Commerce (impossible; Commerce is Core) would
 * simply never have a "Cart" entry to match.
 *
 * The live pill follows the same rule (D-HUB-04, design §7.2): it is data,
 * not a module import. Whether the viewer is at an event arrives as the
 * shared `activeEvent` prop, and the pill only renders what that prop says —
 * the topbar never asks the Admission module anything.
 */
const page = usePage();
const mobileMenuOpen = ref(false);

const user = computed(() => page.props.auth?.user ?? null);
const navItems = computed(() => page.props.nav ?? []);
const brandMenu = computed(() => page.props.brandMenu ?? []);
const cartCount = computed(() => page.props.cart?.count ?? 0);
const livePill = computed(() => livePillFor(page.props.activeEvent ?? null));

/**
 * Overrides the link's own accessible name with the count for the one nav
 * entry that carries a badge, instead of a second `aria-label` on the badge
 * itself, which a screen reader would announce as a confusing second name
 * layered on the link's own text.
 */
function navItemAriaLabel(item: BrandNavItem): string | undefined {
    if (item.url !== '/cart' || cartCount.value === 0) {
        return undefined;
    }

    return cartCount.value === 1
        ? 'Cart, 1 item'
        : `Cart, ${cartCount.value} items`;
}

const { isCurrentUrl } = useCurrentUrl();

const AnnouncementBanner = resolveRegisteredComponent(
    'content/AnnouncementBanner',
);
const NotificationBell = resolveRegisteredComponent(
    'messaging/NotificationBell',
);

/**
 * Inertia keeps the whole page component tree cached per history entry;
 * logging out has to drop it, or the next visitor on this browser can see
 * the previous one's rendered page from the back button.
 */
function handleLogout(): void {
    router.flushAll();
}
</script>

<template>
    <header class="border-b border-border bg-surface">
        <component :is="AnnouncementBanner" v-if="AnnouncementBanner" />

        <Collapsible v-model:open="mobileMenuOpen" class="lg:hidden">
            <div
                class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-2 px-4 sm:px-6"
            >
                <Link :href="home()" class="min-w-0 text-text">
                    <AppLogo />
                </Link>
                <div class="flex items-center gap-1">
                    <component :is="NotificationBell" v-if="NotificationBell" />
                    <CollapsibleTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-full border border-border bg-surface text-text-muted transition hover:bg-surface-muted hover:text-text"
                            :aria-label="
                                mobileMenuOpen ? 'Close menu' : 'Open menu'
                            "
                        >
                            <Menu v-if="!mobileMenuOpen" class="size-4" />
                            <X v-else class="size-4" />
                        </button>
                    </CollapsibleTrigger>
                </div>
            </div>

            <CollapsibleContent class="border-t border-border">
                <nav
                    class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-4 sm:px-6"
                    aria-label="Main"
                >
                    <Link
                        v-if="livePill"
                        :href="livePill.href"
                        data-test="live-pill"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-text"
                    >
                        <span
                            class="mr-1 inline-block size-2 rounded-full"
                            :class="
                                livePill.onSite ? 'bg-success' : 'bg-text-muted'
                            "
                            aria-hidden="true"
                        />
                        {{ livePill.label }}
                    </Link>

                    <component
                        :is="item.external ? 'a' : Link"
                        v-for="item in navItems"
                        :key="item.url"
                        :href="item.url"
                        :aria-current="
                            isCurrentUrl(item.url) ? 'page' : undefined
                        "
                        :aria-label="navItemAriaLabel(item)"
                        class="rounded-lg px-3 py-2 text-sm text-text-muted transition hover:bg-surface-muted hover:text-text aria-[current=page]:bg-subtle aria-[current=page]:text-text"
                    >
                        {{ item.label }}
                        <span
                            v-if="item.url === '/cart' && cartCount > 0"
                            aria-hidden="true"
                            class="ml-1 inline-flex min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-medium text-on-accent"
                        >
                            {{ cartCount }}
                        </span>
                    </component>

                    <BrandMenuMobile :entries="brandMenu" />

                    <template v-if="user">
                        <span class="my-2 border-t border-border" />
                        <UserMenuContent
                            :user="user"
                            variant="plain"
                            @logout="handleLogout"
                        />
                    </template>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-lg px-3 py-2 text-sm text-text-muted transition hover:bg-surface-muted hover:text-text"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="rounded-lg bg-accent px-4 py-2.5 text-center text-sm font-medium text-on-accent hover:bg-accent/90"
                        >
                            Sign up
                        </Link>
                    </template>
                </nav>
            </CollapsibleContent>
        </Collapsible>

        <div
            class="mx-auto hidden max-w-7xl items-center justify-between gap-6 px-6 py-3 lg:flex"
        >
            <div class="flex min-w-0 items-center gap-6">
                <Link :href="home()" class="min-w-0 text-text">
                    <AppLogo />
                </Link>
                <nav class="flex items-center gap-1" aria-label="Main">
                    <component
                        :is="item.external ? 'a' : Link"
                        v-for="item in navItems"
                        :key="item.url"
                        :href="item.url"
                        :aria-current="
                            isCurrentUrl(item.url) ? 'page' : undefined
                        "
                        :aria-label="navItemAriaLabel(item)"
                        class="rounded-md px-3 py-2 text-sm whitespace-nowrap text-text-muted transition hover:bg-surface-muted hover:text-text aria-[current=page]:bg-subtle aria-[current=page]:text-text"
                    >
                        {{ item.label }}
                        <span
                            v-if="item.url === '/cart' && cartCount > 0"
                            aria-hidden="true"
                            class="ml-1 inline-flex min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-medium text-on-accent"
                        >
                            {{ cartCount }}
                        </span>
                    </component>
                    <BrandMenuDesktop
                        v-if="brandMenu.length > 0"
                        :entries="brandMenu"
                    />
                </nav>
            </div>

            <div class="flex items-center gap-2">
                <Link
                    v-if="livePill"
                    :href="livePill.href"
                    data-test="live-pill"
                    class="inline-flex items-center gap-2 rounded-full border border-accent/40 bg-accent/10 px-3 py-1 text-sm font-medium text-text hover:bg-accent/20"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="
                            livePill.onSite ? 'bg-success' : 'bg-text-muted'
                        "
                        aria-hidden="true"
                    />
                    {{ livePill.label }}
                </Link>

                <component :is="NotificationBell" v-if="NotificationBell" />

                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full border border-border py-1 pr-3 pl-1 text-left transition hover:bg-surface-muted"
                            data-test="account-menu-button"
                        >
                            <UserInfo :user="user" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="min-w-56 rounded-lg"
                        align="end"
                        :side-offset="4"
                    >
                        <UserMenuContent :user="user" @logout="handleLogout" />
                    </DropdownMenuContent>
                </DropdownMenu>

                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-md px-3 py-2 text-sm whitespace-nowrap text-text-muted hover:text-text"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="rounded-md bg-accent px-4 py-2 text-sm font-medium whitespace-nowrap text-on-accent hover:bg-accent/90"
                    >
                        Sign up
                    </Link>
                </template>
            </div>
        </div>
    </header>
</template>
