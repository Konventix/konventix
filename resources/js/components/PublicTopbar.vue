<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { home, login, register } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

/**
 * Public-facing topbar for the brand landing page and static brand pages
 * (generalized from LanCore's `PublicTopbar`). `name` is the brand's display
 * name, passed by the caller from the shared `name` Inertia prop rather than
 * read here directly, so this component stays reusable without depending on
 * a specific shared-prop shape.
 *
 * Kept deliberately simple per scope: a single "Home" link plus
 * auth-aware CTAs (account settings when logged in, otherwise
 * login/register) — no dynamic published-pages nav, which would need a new
 * shared Inertia prop. Brand hosts have no dashboard; a shop link joins
 * here later.
 */
defineProps<{
    name: string;
}>();

const page = usePage();
const mobileMenuOpen = ref(false);
</script>

<template>
    <header class="border-b border-border">
        <Collapsible v-model:open="mobileMenuOpen" class="lg:hidden">
            <div
                class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4 sm:px-6"
            >
                <Link :href="home()" class="flex items-center gap-2 text-text">
                    <AppLogoIcon class="size-6 fill-current" />
                    <span class="font-semibold">{{ name }}</span>
                </Link>
                <CollapsibleTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex size-9 items-center justify-center rounded-full border border-border bg-surface text-text-muted transition hover:bg-surface-muted hover:text-text"
                        aria-label="Toggle menu"
                    >
                        <Menu v-if="!mobileMenuOpen" class="size-4" />
                        <X v-else class="size-4" />
                    </button>
                </CollapsibleTrigger>
            </div>

            <CollapsibleContent class="border-t border-border">
                <nav
                    class="mx-auto flex max-w-5xl flex-col gap-2 px-4 py-4 sm:px-6"
                >
                    <Link
                        :href="home()"
                        class="rounded-lg px-3 py-2 text-sm text-text-muted transition hover:bg-surface-muted hover:text-text"
                    >
                        Home
                    </Link>
                    <Link
                        v-if="page.props.auth.user"
                        :href="editProfile()"
                        class="rounded-lg bg-accent px-4 py-2.5 text-center text-sm font-medium text-on-accent hover:bg-accent/90"
                    >
                        Account
                    </Link>
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
                            Register
                        </Link>
                    </template>
                </nav>
            </CollapsibleContent>
        </Collapsible>

        <div
            class="mx-auto hidden max-w-5xl items-center justify-between gap-4 px-6 py-4 lg:flex"
        >
            <Link :href="home()" class="flex items-center gap-2 text-text">
                <AppLogoIcon class="size-7 fill-current" />
                <span class="text-lg font-semibold">{{ name }}</span>
            </Link>
            <nav class="flex items-center gap-4">
                <Link
                    :href="home()"
                    class="text-sm whitespace-nowrap text-text-muted hover:text-text"
                >
                    Home
                </Link>
                <Link
                    v-if="page.props.auth.user"
                    :href="editProfile()"
                    class="rounded-md bg-accent px-4 py-2 text-sm font-medium whitespace-nowrap text-on-accent hover:bg-accent/90"
                >
                    Account
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="text-sm whitespace-nowrap text-text-muted hover:text-text"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="rounded-md bg-accent px-4 py-2 text-sm font-medium whitespace-nowrap text-on-accent hover:bg-accent/90"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </div>
    </header>
</template>
