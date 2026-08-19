<script setup lang="ts">
/**
 * Applies the draft's effective tokens (overrides merged onto platform
 * defaults) as inline CSS custom properties on a wrapper div, then renders
 * sample UI underneath using only semantic Tailwind classes (bg-surface,
 * text-text-muted, ...) — never a raw palette utility. The samples must
 * exercise every token in TokenCatalog so no override is invisible here —
 * extend them when the catalog grows. Inline styles win
 * over the inherited :root cascade, so the panel previews the draft
 * regardless of what the operator's own light/dark preference is.
 *
 * Previews whichever scheme the editor is on: the caller passes the effective
 * tokens for that scheme, so a dark draft is judged against the dark defaults
 * rather than against the light ones.
 */
import { computed } from 'vue';

const props = defineProps<{
    tokens: Record<string, string>;
}>();

const cssVars = computed<Record<string, string>>(() =>
    Object.fromEntries(
        Object.entries(props.tokens).map(([name, value]) => [
            `--${name}`,
            value,
        ]),
    ),
);
</script>

<template>
    <div class="space-y-2">
        <div
            class="text-xs font-semibold tracking-wide text-text-muted uppercase"
        >
            Preview
        </div>
        <div
            class="overflow-hidden rounded-xl border border-border"
            :style="cssVars"
        >
            <div class="flex flex-col gap-4 bg-surface p-4 text-text">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md bg-accent px-3 py-1.5 text-xs font-medium text-on-accent"
                    >
                        Primary action
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-input bg-surface px-3 py-1.5 text-xs font-medium text-text"
                    >
                        Secondary
                    </button>
                </div>

                <div
                    class="rounded-lg bg-surface-inverted p-3 text-xs font-medium text-text-inverted"
                >
                    Inverted banner — inverted text on the inverted surface
                    token.
                </div>

                <div
                    class="rounded-lg border border-border bg-surface-raised p-4 shadow-xs"
                >
                    <div class="mb-1 text-sm font-semibold">Card heading</div>
                    <div class="text-xs text-text-muted">
                        Supporting copy uses the muted text token, on the raised
                        surface token.
                    </div>
                </div>

                <div
                    class="rounded-lg bg-surface-muted p-3 text-xs text-text-muted"
                >
                    Muted panel — muted text on the muted surface token.
                </div>

                <div
                    class="rounded-lg border border-danger/20 bg-danger-surface p-3 text-xs text-danger"
                >
                    Danger notice — danger text on the danger surface token.
                </div>

                <div class="flex flex-wrap gap-2">
                    <span
                        class="rounded-full bg-danger px-2 py-0.5 text-[10px] font-medium text-danger-contrast"
                    >
                        Danger
                    </span>
                    <span
                        class="rounded-full bg-success px-2 py-0.5 text-[10px] font-medium text-success-contrast"
                    >
                        Success
                    </span>
                    <span
                        class="rounded-full bg-warning px-2 py-0.5 text-[10px] font-medium text-warning-contrast"
                    >
                        Warning
                    </span>
                </div>

                <div class="text-[10px] text-text-muted">
                    Focus ring:
                    <button
                        type="button"
                        class="ml-1 inline-block rounded-md border border-input bg-surface px-2 py-0.5 text-[10px] text-text outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Tab to focus
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
