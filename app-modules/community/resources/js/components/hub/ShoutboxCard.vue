<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ShoutboxCompose from '../shoutbox/ShoutboxCompose.vue';
import { useShoutboxWall } from '../shoutbox/useShoutboxWall';
import WallLine from '../shoutbox/WallLine.vue';

/**
 * The live hub's shoutbox card (design §8): the last few lines of the
 * running event's wall, live over the room channel, and an inline compose
 * box. The whole room is one link away.
 *
 * The props are `ShoutboxCardData`'s fields, spread by the hub shell
 * (`v-bind="card.payload"`). The three URLs arrive in the payload because a
 * hub component imports no route helper and nothing of another module's
 * (`tests/Feature/HubComponentBoundaryTest.php`) — only the shared frontend
 * and its own module's `shoutbox/` pieces, which the room page uses too.
 *
 * The card keeps only its last few lines as new ones arrive, so a busy
 * wall does not grow the hub.
 */
type Props = {
    wall: Konventix.Community.Data.ShoutboxWallData;
    roomUrl: string;
    messagesUrl: string;
    postUrl: string;
};

const props = defineProps<Props>();

/** `WallReader::CARD_LINES` — what the server opened the card with. */
const CARD_LINES = 5;

const { entries, pending, refusal, remaining, canWrite, post } =
    useShoutboxWall(props.wall, {
        messages: props.messagesUrl,
        post: props.postUrl,
    });
</script>

<template>
    <div class="flex flex-col gap-4">
        <ul
            v-if="entries.length > 0 || pending.length > 0"
            class="flex flex-col gap-3"
            aria-live="polite"
            :aria-label="$t('Latest messages')"
        >
            <WallLine
                v-for="entry in entries.slice(-CARD_LINES)"
                :key="entry.id"
                :entry="entry"
            />
            <li
                v-for="line in pending"
                :key="line.key"
                class="text-sm text-text-muted"
            >
                {{ line.body }}
                <span class="text-xs">{{ $t('(sending …)') }}</span>
            </li>
        </ul>
        <p v-else class="text-sm text-text-muted">
            {{ $t("It's quiet here so far. Write the first message.") }}
        </p>

        <ShoutboxCompose
            :can-write="canWrite"
            :notice="props.wall.notice"
            :refusal="refusal"
            :remaining="remaining"
            :max-length="props.wall.maxLength"
            :send="post"
        />

        <Link
            :href="props.roomUrl"
            class="self-start text-sm text-accent underline-offset-4 hover:underline"
        >
            {{ $t('Open the whole room') }}
        </Link>
    </div>
</template>
