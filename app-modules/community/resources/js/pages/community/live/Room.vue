<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import RoomController from '@/actions/Konventix/Community/Http/Controllers/RoomController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { hub } from '@/routes/platform';
import ReportDialog from '../../../components/shoutbox/ReportDialog.vue';
import ShoutboxCompose from '../../../components/shoutbox/ShoutboxCompose.vue';
import { useRoomPresence } from '../../../components/shoutbox/useRoomPresence';
import { useShoutboxWall } from '../../../components/shoutbox/useShoutboxWall';
import type { WallLine } from '../../../components/shoutbox/wall';
import { isTombstone } from '../../../components/shoutbox/wall';
import WallLineItem from '../../../components/shoutbox/WallLine.vue';

/**
 * The event's room at `/live/shoutbox` (design §8): the full-height wall,
 * who is here, and scrolling upward into what was said before. Admitted
 * participants only — the controller answers everyone else with a 404
 * before this renders. German throughout (ADR-0001).
 *
 * The wall, the channel and the compose box are the same pieces the hub
 * card uses (`components/shoutbox/`); this page adds the older pages, the
 * report button and the presence heartbeat.
 */
const props = defineProps<{
    room: Konventix.Community.Data.ShoutboxRoomData;
}>();

const page = usePage();
const viewerId = computed(() => String(page.props.auth?.user?.id ?? ''));

const {
    entries,
    pending,
    hasOlder,
    loadingOlder,
    refusal,
    remaining,
    canWrite,
    post,
    loadOlder,
} = useShoutboxWall(props.room.wall, {
    messages: RoomController.messages().url,
    post: RoomController.store().url,
});

const { presence } = useRoomPresence(props.room.presence, {
    beat: RoomController.presence().url,
    leave: RoomController.leave().url,
});

const reporting = ref<WallLine | null>(null);

const scroller = ref<HTMLElement | null>(null);
const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

function nearBottom(): boolean {
    const element = scroller.value;

    return (
        element === null ||
        element.scrollHeight - element.scrollTop - element.clientHeight < 80
    );
}

function toBottom(): void {
    if (scroller.value !== null) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
    }
}

/**
 * Upward pages keep the reader where they were: the lines above grow, the
 * one they were reading stays under their eyes.
 */
async function older(): Promise<void> {
    const element = scroller.value;
    const fromBottom =
        element === null ? 0 : element.scrollHeight - element.scrollTop;

    await loadOlder();
    await nextTick();

    if (element !== null) {
        element.scrollTop = element.scrollHeight - fromBottom;
    }
}

// A new line follows the reader down only if they were already at the
// bottom; somebody scrolled up to read is left alone.
watch(
    () => [entries.value[entries.value.length - 1]?.id, pending.value.length],
    async () => {
        const follow = nearBottom();
        await nextTick();

        if (follow) {
            toBottom();
        }
    },
);

onMounted(() => {
    toBottom();

    if (
        sentinel.value !== null &&
        typeof IntersectionObserver !== 'undefined'
    ) {
        observer = new IntersectionObserver(
            (seen) => {
                if (seen.some((entry) => entry.isIntersecting)) {
                    void older();
                }
            },
            { root: scroller.value },
        );
        observer.observe(sentinel.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

const statusLabel: Record<Konventix.Community.Enums.ConnectionStatus, string> =
    {
        online: 'online',
        away: 'abwesend',
        offline: 'offline',
    };
</script>

<template>
    <Head title="Shoutbox" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-8 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                as="h1"
                title="Shoutbox"
                :description="props.room.eventName ?? undefined"
            />
            <Link
                :href="hub().url"
                class="text-sm text-accent underline-offset-4 hover:underline"
            >
                Zurück zum Live-Hub
            </Link>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-[1fr_16rem]">
            <section class="flex flex-col gap-4" aria-label="Nachrichten">
                <div
                    ref="scroller"
                    class="flex h-[60vh] flex-col gap-3 overflow-y-auto rounded-md border border-border bg-surface p-4"
                >
                    <div ref="sentinel" aria-hidden="true" />
                    <Button
                        v-if="hasOlder"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="self-center"
                        :disabled="loadingOlder"
                        @click="older"
                    >
                        Ältere Nachrichten laden
                    </Button>
                    <p
                        v-if="entries.length === 0 && pending.length === 0"
                        class="text-sm text-text-muted"
                    >
                        Noch ist es still hier. Schreib die erste Nachricht.
                    </p>
                    <ul class="flex flex-col gap-3" aria-live="polite">
                        <WallLineItem
                            v-for="entry in entries"
                            :key="entry.id"
                            :entry="entry"
                        >
                            <template
                                v-if="
                                    !isTombstone(entry) &&
                                    entry.authorUserId !== viewerId
                                "
                                #actions
                            >
                                <button
                                    type="button"
                                    class="ml-auto text-text-muted hover:text-text"
                                    aria-label="Nachricht melden"
                                    @click="reporting = entry"
                                >
                                    <Flag class="size-3.5" aria-hidden="true" />
                                </button>
                            </template>
                        </WallLineItem>
                        <li
                            v-for="line in pending"
                            :key="line.key"
                            class="text-sm text-text-muted"
                        >
                            {{ line.body }}
                            <span class="text-xs">(wird gesendet …)</span>
                        </li>
                    </ul>
                </div>

                <ShoutboxCompose
                    :can-write="canWrite"
                    :notice="props.room.wall.notice"
                    :refusal="refusal"
                    :remaining="remaining"
                    :max-length="props.room.wall.maxLength"
                    :send="post"
                />
            </section>

            <aside
                class="flex flex-col gap-3"
                aria-labelledby="presence-heading"
            >
                <h2
                    id="presence-heading"
                    class="text-base font-medium text-text"
                >
                    Wer ist da
                </h2>
                <p v-if="presence.length === 0" class="text-sm text-text-muted">
                    Gerade niemand.
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="person in presence"
                        :key="person.userId"
                        class="flex items-center gap-2 text-sm text-text"
                    >
                        <!-- Checked-In + Offline is a real state: a badge
                             with no dot (design §5). -->
                        <span
                            v-if="person.status !== 'offline'"
                            class="inline-block size-2 shrink-0 rounded-full"
                            :class="
                                person.status === 'online'
                                    ? 'bg-success'
                                    : 'bg-warning'
                            "
                            aria-hidden="true"
                        />
                        <span class="truncate">
                            {{ person.displayName ?? 'Unbekannt' }}
                        </span>
                        <span class="sr-only"
                            >({{ statusLabel[person.status] }})</span
                        >
                        <Badge
                            v-if="person.checkedIn"
                            variant="secondary"
                            class="ml-auto"
                        >
                            Eingecheckt
                        </Badge>
                    </li>
                </ul>
            </aside>
        </div>

        <ReportDialog
            :line="reporting"
            :report-url="(id) => RoomController.report(id).url"
            :note-max-length="props.room.noteMaxLength"
            @close="reporting = null"
        />
    </div>
</template>
