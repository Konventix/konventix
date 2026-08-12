<script setup lang="ts">
/**
 * Client-side mirror of the server's BFSG contrast rule (ADR-0008, resources/
 * js/lib/contrast.ts). Shows every catalog pair against the current draft
 * (overrides merged onto platform defaults) so the operator sees a failure
 * before submitting — the server re-checks on save and is authoritative, and
 * refuses rather than warns.
 */
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { contrastRatio, MIN_CONTRAST_RATIO } from '@/lib/contrast';

const props = defineProps<{
    pairs: Konventix.Platform.Data.ThemeContrastPairData[];
    effectiveTokens: Record<string, string>;
}>();

const rows = computed(() =>
    props.pairs.map((pair) => {
        const ratio = contrastRatio(
            props.effectiveTokens[pair.text] ?? '',
            props.effectiveTokens[pair.surface] ?? '',
        );

        return {
            text: pair.text,
            surface: pair.surface,
            ratio,
            passes: ratio !== null && ratio >= MIN_CONTRAST_RATIO,
        };
    }),
);
</script>

<template>
    <div class="space-y-2 rounded-xl border border-border p-4">
        <div
            class="text-xs font-semibold tracking-wide text-text-muted uppercase"
        >
            Contrast (BFSG, ADR-0008)
        </div>
        <ul class="space-y-1.5">
            <li
                v-for="row in rows"
                :key="`${row.text}-${row.surface}`"
                class="flex items-center justify-between gap-2 text-xs"
            >
                <span class="text-text-muted">
                    <code>--{{ row.text }}</code> on
                    <code>--{{ row.surface }}</code>
                </span>
                <Badge :variant="row.passes ? 'secondary' : 'destructive'">
                    {{
                        row.ratio === null
                            ? 'invalid color'
                            : `${row.ratio.toFixed(2)}:1`
                    }}
                </Badge>
            </li>
        </ul>
        <p class="text-[11px] text-text-muted">
            Estimate only — the server re-checks every pair the save touches
            on submit and refuses a non-compliant save rather than warning
            about it.
        </p>
    </div>
</template>
