<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useCookieSettingsDialog } from '@/composables/useCookieSettingsDialog';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';

/**
 * The footer of every brand-facing page: the operator's own entries, the
 * running version, and who made this.
 *
 * Entries are operator-wide (see the `footer_links` migration) and arrive as
 * data on the shared `footer` prop, so this component knows no destinations
 * of its own. A `dialog` entry opens a component the module registry
 * resolves by key — the seam the notification bell and the announcement
 * banner already use — and is skipped entirely when nothing ships under that
 * key, which is how "Cookie settings" can be configured before the dialog
 * behind it exists.
 */
const page = usePage();

const version = computed(() => page.props.footer?.version ?? null);

const columns = computed(() => page.props.footer?.columns ?? []);

/** Which dialog key, if any, the visitor has opened — except cookie-settings,
 *  which shares state with every other surface that can open it. */
const openDialog = ref<string | null>(null);

const cookieSettings = useCookieSettingsDialog();

const links = computed(() =>
    (page.props.footer?.links ?? []).filter(
        (link) =>
            link.kind === 'url' ||
            resolveRegisteredComponent(`platform/dialogs/${link.dialogKey}`) !==
                null,
    ),
);

function openLinkDialog(key: string | null): void {
    if (key === 'cookie-settings') {
        cookieSettings.open();

        return;
    }

    openDialog.value = key;
}

function dialogComponent(key: string | null) {
    return key === null
        ? null
        : resolveRegisteredComponent(`platform/dialogs/${key}`);
}
</script>

<template>
    <footer class="mt-auto border-t border-border bg-surface">
        <div
            v-if="columns.length > 0"
            class="mx-auto grid max-w-7xl gap-8 border-b border-border px-6 py-8 sm:grid-cols-2 lg:grid-cols-4"
        >
            <section v-for="(column, index) in columns" :key="index">
                <h2
                    v-if="column.heading"
                    class="mb-3 text-sm font-semibold text-text"
                >
                    {{ column.heading }}
                </h2>
                <template
                    v-for="(block, blockIndex) in column.blocks"
                    :key="`${block.key}-${blockIndex}`"
                >
                    <component
                        :is="resolveRegisteredComponent(block.component)"
                        v-if="resolveRegisteredComponent(block.component)"
                        v-bind="block.props"
                    />
                </template>
            </section>
        </div>

        <div
            class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-6 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between"
        >
            <nav
                v-if="links.length > 0"
                class="flex flex-wrap items-center gap-x-4 gap-y-2"
                aria-label="Legal and policies"
            >
                <template v-for="link in links" :key="link.label">
                    <a
                        v-if="link.kind === 'url' && link.opensInNewTab"
                        :href="link.url ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hover:text-text"
                    >
                        {{ link.label }}
                    </a>
                    <Link
                        v-else-if="link.kind === 'url'"
                        :href="link.url ?? '#'"
                        class="hover:text-text"
                    >
                        {{ link.label }}
                    </Link>
                    <button
                        v-else
                        type="button"
                        class="hover:text-text"
                        @click="openLinkDialog(link.dialogKey)"
                    >
                        {{ link.label }}
                    </button>
                </template>
            </nav>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span v-if="version">Version {{ version }}</span>
                <span>
                    Built on
                    <a
                        href="https://github.com/konventix/konventix"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline underline-offset-4 hover:text-text"
                    >
                        Konventix
                    </a>
                </span>
            </div>
        </div>

        <component
            :is="dialogComponent(openDialog)"
            v-if="openDialog !== null && dialogComponent(openDialog)"
            :open="true"
            @close="openDialog = null"
        />
        <component
            :is="dialogComponent('cookie-settings')"
            v-if="dialogComponent('cookie-settings')"
            :open="cookieSettings.isOpen.value"
            @close="cookieSettings.close()"
        />
    </footer>
</template>
