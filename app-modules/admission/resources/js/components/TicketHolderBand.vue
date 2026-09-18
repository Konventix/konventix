<script setup lang="ts">
import { Armchair } from '@lucide/vue';

/**
 * The middle of the ticket (#292 §4.5): the scan code in an opaque,
 * fixed-size box so it never scales below scannable, the door code under
 * it for a hand-typed fallback, and who it admits as what. `qrSvg` is
 * server-rendered markup of the admission's current token (ADR-0061); the
 * box is `role="img"` named by the door code, since the SVG itself says
 * nothing a screen reader can use.
 */
const props = defineProps<{
    qrSvg: string;
    doorCode: string;
    holderName: string;
    role: string;
    validRange: string;
    seatLabel: string | null;
    checkedIn: boolean;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-4 p-5 sm:flex-row sm:items-start sm:gap-8"
    >
        <div class="flex flex-col items-center gap-2">
            <div
                role="img"
                :aria-label="`Scan code, door code ${props.doorCode}`"
                class="size-44 shrink-0 rounded-lg bg-white p-2 [&>svg]:size-full"
                v-html="props.qrSvg"
            />
            <p class="font-mono text-sm tracking-widest text-text">
                {{ props.doorCode }}
            </p>
        </div>

        <dl class="grid flex-1 grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-text-muted">Admits</dt>
            <dd class="font-medium text-text">{{ props.holderName }}</dd>
            <dt class="text-text-muted">As</dt>
            <dd class="capitalize">{{ props.role }}</dd>
            <dt class="text-text-muted">Valid</dt>
            <dd>{{ props.validRange }}</dd>
            <dt class="text-text-muted">Seat</dt>
            <dd class="flex items-center gap-1">
                <Armchair class="size-4 text-text-muted" aria-hidden="true" />
                {{ props.seatLabel ?? 'No seat chosen yet' }}
            </dd>
            <template v-if="props.checkedIn">
                <dt class="text-text-muted">Status</dt>
                <dd>
                    <span
                        class="rounded-full bg-success/15 px-2 py-0.5 text-xs font-medium text-success"
                    >
                        Checked in
                    </span>
                </dd>
            </template>
        </dl>
    </div>
</template>
