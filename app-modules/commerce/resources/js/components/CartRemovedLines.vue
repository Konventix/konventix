<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { ref } from 'vue';
import { restore as restoreLine } from '@/routes/commerce/cart/lines';

/**
 * #219's undo window, on the cart page.
 *
 * The trash button stays one click — the operator chose an undo window over
 * a confirmation dialog on 2026-09-22 — and this is the other half of that
 * choice: the line the buyer just removed is listed here with a way to put
 * it back, and with what will happen if they do not.
 *
 * It can promise that because nothing irreversible has happened yet.
 * `RemoveCartLine` only stamps `cart_lines.removed_at`; the registration
 * behind the line and the hold it carries are untouched, so "Put it back"
 * is a single column write and not a repair of a cancellation. The window
 * is the rest of the cart's own hold — removing a line pushes that deadline
 * forward like every other cart change — and it closes by itself: at
 * checkout, or when the hold runs out, at which point the registration is
 * cancelled and the buyer is mailed about a removal rather than an expiry
 * (#220).
 *
 * Extracted rather than grown into `pages/cart/Show.vue`, which is already
 * at the ~300-line limit CLAUDE_extra sets (`.ai/rules/pages.md`).
 *
 * `role="status"` because this appears in response to something the buyer
 * did and has to reach a screen reader without stealing focus (ADR-0055,
 * `.ai/rules/js.md`); the deadline itself is named in prose, not ticked, for
 * the same reason the cart's own countdown is a `role="timer"` elsewhere.
 */
type RemovedLine = Konventix.Commerce.Data.RemovedCartLineData;

defineProps<{
    lines: RemovedLine[];
}>();

// Only the row being restored disables its own button, so a second removed
// line stays usable while one request is in flight — the same rule the
// line list applies to its own controls.
const restoringLineId = ref<string | null>(null);

function restore(line: RemovedLine): void {
    restoringLineId.value = line.id;
    router.post(
        restoreLine(line.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => (restoringLineId.value = null),
        },
    );
}
</script>

<template>
    <div
        role="status"
        class="flex flex-col gap-3 rounded-xl border border-warning/30 bg-warning/10 p-4"
    >
        <div class="flex flex-col gap-1">
            <p class="text-sm font-medium text-text">
                {{ $t('Removed from your cart — you can still put it back.') }}
            </p>
            <p class="text-sm text-text-muted">
                {{
                    $t(
                        'Nothing has been cancelled yet. If you leave it out, the registration behind it is given up when your cart expires, and we will email you about it.',
                    )
                }}
            </p>
        </div>

        <ul class="flex flex-col gap-2">
            <li
                v-for="line in lines"
                :key="line.id"
                class="flex items-center justify-between gap-3"
            >
                <div class="flex min-w-0 flex-1 flex-col">
                    <Link
                        v-if="line.url"
                        :href="line.url"
                        class="truncate text-sm font-medium text-text underline-offset-4 hover:underline"
                    >
                        {{ line.label }}
                    </Link>
                    <span v-else class="truncate text-sm font-medium text-text">
                        {{ line.label }}
                    </span>
                    <span class="text-sm text-text-muted">
                        {{
                            $t('Qty :quantity', {
                                quantity: String(line.quantity),
                            })
                        }}
                    </span>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm text-text transition hover:bg-surface-muted disabled:pointer-events-none disabled:opacity-50"
                    :disabled="restoringLineId === line.id"
                    :aria-label="
                        $t('Put :item back in your cart', { item: line.label })
                    "
                    @click="restore(line)"
                >
                    <Undo2 class="size-4" />
                    {{ $t('Put it back') }}
                </button>
            </li>
        </ul>
    </div>
</template>
