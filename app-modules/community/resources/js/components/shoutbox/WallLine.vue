<script setup lang="ts">
import { formatTime } from '@/lib/datetime';
import type { WallEntry } from './wall';
import { isTombstone } from './wall';

/**
 * One entry of the wall, as participants see it. A removed line renders as
 * "Removed by the organisers" and carries neither body nor author (spec §4.2) —
 * the tombstone the server sends has neither, so there is nothing here to
 * leak. The `actions` slot is the room page's report button; the card has
 * none.
 */
defineProps<{ entry: WallEntry }>();
</script>

<template>
    <li class="flex flex-col gap-0.5">
        <p v-if="isTombstone(entry)" class="text-sm text-text-muted italic">
            {{ $t('Removed by the organisers') }}
        </p>
        <template v-else>
            <div class="flex items-baseline gap-2">
                <span class="text-sm font-medium text-text">
                    {{ entry.authorDisplayName ?? $t('Unknown') }}
                </span>
                <time
                    :datetime="entry.postedAt"
                    class="text-xs text-text-muted"
                >
                    {{ formatTime(entry.postedAt) }}
                </time>
                <slot name="actions" />
            </div>
            <p class="text-sm break-words whitespace-pre-wrap text-text">
                {{ entry.body }}
            </p>
        </template>
    </li>
</template>
