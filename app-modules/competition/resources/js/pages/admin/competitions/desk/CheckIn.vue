<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import {
    desk as competitionDesk,
    entrants as competitionEntrants,
} from '@/routes/admin/competitions';
import { checkIn as checkInEntrant } from '@/routes/admin/entrants';
import { undo as undoCheckIn } from '@/routes/admin/entrants/check-in';

/**
 * The desk's check-in list (design §6).
 *
 * One row per entrant that is still in the field, and per row the one thing
 * the person at the table needs: can this team go in. With
 * `requiresEventCheckIn` each roster member is marked with whether the
 * **event door** has seen them — projected into this module's own read model
 * from Admission's events, never queried out of Admission — so a refusal is
 * visible before the button is pressed rather than after. And when a click
 * *is* refused anyway, the controller's field error is rendered on that
 * entrant's own row: a button that silently does nothing is the one thing a
 * desk with a queue behind it cannot work with.
 *
 * **Both buttons are always offered, whatever the mode** (§6): the desk may
 * check a team in and take it back regardless of whether the competition is
 * set to `none`, `self_service` or `desk`. The mode is context at the top of
 * the page, not a condition on the controls — a desk that cannot correct what
 * a captain got wrong is not a desk.
 *
 * Console copy is English. Timestamps go through `formatDateTime()`, which
 * reads the operator's own zone and format (.ai/rules/js.md).
 *
 * a11y: the door state is a word, never colour alone, and every button
 * carries its own text (.ai/rules/js.md).
 */
type Member = { userId: string; displayName: string; atTheDoor: boolean };

type EntrantRow = {
    id: string;
    state: string;
    teamName: string;
    checkedInAt: string | null;
    ready: boolean;
    members: Member[];
};

const props = defineProps<{
    competition: {
        id: string;
        name: string;
        status: string;
        checkInMode: string;
        checkInModeLabel: string;
        requiresEventCheckIn: boolean;
        checkInOpensMinutesBefore: number;
        startsAt: string | null;
        selfServiceOpensAt: string | null;
        selfServiceIsOpen: boolean;
    };
    entrants: EntrantRow[];
}>();

const checkedIn = computed(() =>
    props.entrants.filter((entrant) => entrant.state === 'checked_in'),
);

const waiting = computed(() =>
    props.entrants.filter((entrant) => entrant.state !== 'checked_in'),
);

/** Who this team is still missing at the door — the reason a check-in refuses. */
function stillOutside(entrant: EntrantRow): Member[] {
    if (!props.competition.requiresEventCheckIn) {
        return [];
    }

    return entrant.members.filter((member) => !member.atTheDoor);
}

/**
 * The controller answers a refused check-in with a field error on `entrant`
 * (`ValidationException`), which names who is still outside. Without this the
 * click is silent and the desk is left pressing a button that does nothing —
 * the worst thing to happen at a table with a queue behind it. Keyed by
 * entrant so the message appears on the row it belongs to.
 */
const refusals = ref<Record<string, string | undefined>>({});

function checkIn(entrant: EntrantRow): void {
    refusals.value = { ...refusals.value, [entrant.id]: undefined };

    router.post(
        checkInEntrant(entrant.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                refusals.value = {
                    ...refusals.value,
                    [entrant.id]: errors.entrant,
                };
            },
        },
    );
}

function undo(entrant: EntrantRow): void {
    refusals.value = { ...refusals.value, [entrant.id]: undefined };

    router.delete(undoCheckIn(entrant.id).url, {
        preserveScroll: true,
        onError: (errors) => {
            refusals.value = {
                ...refusals.value,
                [entrant.id]: errors.entrant,
            };
        },
    });
}
</script>

<template>
    <Head :title="$t('Check-in — :name', { name: props.competition.name })" />

    <div class="flex w-full flex-col gap-6 p-4">
        <Heading
            as="h1"
            :title="$t('Check-in — :name', { name: props.competition.name })"
            :description="
                $t(':checked of :total team(s) checked in.', {
                    checked: String(checkedIn.length),
                    total: String(props.entrants.length),
                })
            "
        />

        <section
            class="flex flex-col gap-2 rounded-xl border border-border bg-surface-raised p-4 text-sm"
        >
            <p class="text-text">
                {{ $t('Mode:') }}
                <strong>{{ props.competition.checkInModeLabel }}</strong>
                <template v-if="props.competition.requiresEventCheckIn">
                    {{
                        $t(
                            '— every roster member must have been scanned at the event door.',
                        )
                    }}
                </template>
            </p>

            <p
                v-if="props.competition.checkInMode === 'self_service'"
                class="text-text-muted"
            >
                <template v-if="props.competition.selfServiceOpensAt">
                    {{
                        $t(
                            'Captains may check in from :time (:minutes minutes before the start) until the competition starts.',
                            {
                                time: formatDateTime(
                                    props.competition.selfServiceOpensAt,
                                ),
                                minutes: String(
                                    props.competition.checkInOpensMinutesBefore,
                                ),
                            },
                        )
                    }}
                    {{
                        props.competition.selfServiceIsOpen
                            ? $t('The window is open now.')
                            : $t('It is not open yet.')
                    }}
                </template>
                <template v-else>
                    {{
                        $t(
                            'This competition has no start time, so the self-service window never opens. Set one on the competition, or check the teams in here.',
                        )
                    }}
                </template>
            </p>

            <p
                v-else-if="props.competition.checkInMode === 'none'"
                class="text-text-muted"
            >
                {{
                    $t(
                        'This competition does not require a check-in: every entered team counts as ready. You may still tick teams off here.',
                    )
                }}
            </p>

            <p v-else class="text-text-muted">
                {{ $t('Only this desk checks teams in.') }}
            </p>

            <p class="flex flex-wrap gap-4">
                <Link
                    :href="competitionDesk(props.competition.id).url"
                    class="underline underline-offset-4 hover:text-accent"
                >
                    {{ $t('Tournament desk') }}
                </Link>
                <Link
                    :href="competitionEntrants(props.competition.id).url"
                    class="underline underline-offset-4 hover:text-accent"
                >
                    {{ $t('Entrants and rosters') }}
                </Link>
            </p>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-medium text-text">{{ $t('Waiting') }}</h2>

            <p v-if="waiting.length === 0" class="text-sm text-text-muted">
                {{ $t('Every team is checked in.') }}
            </p>

            <article
                v-for="entrant in waiting"
                :key="entrant.id"
                class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-border bg-surface-raised p-4"
            >
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="font-medium text-text">{{
                        entrant.teamName
                    }}</span>

                    <ul class="flex flex-wrap gap-x-3 gap-y-1 text-sm">
                        <li
                            v-for="member in entrant.members"
                            :key="member.userId"
                            :class="
                                props.competition.requiresEventCheckIn &&
                                !member.atTheDoor
                                    ? 'text-warning'
                                    : 'text-text-muted'
                            "
                        >
                            {{ member.displayName }}
                            <template
                                v-if="props.competition.requiresEventCheckIn"
                            >
                                —
                                {{
                                    member.atTheDoor
                                        ? $t('at the door')
                                        : $t('not scanned')
                                }}
                            </template>
                        </li>
                    </ul>

                    <p
                        v-if="stillOutside(entrant).length > 0"
                        class="text-sm text-warning"
                    >
                        {{
                            $t(
                                ':count player(s) have not been through the door yet; the check-in will refuse.',
                                {
                                    count: String(stillOutside(entrant).length),
                                },
                            )
                        }}
                    </p>

                    <InputError :message="refusals[entrant.id]" />
                </div>

                <Button size="sm" @click="checkIn(entrant)">{{
                    $t('Check in')
                }}</Button>
            </article>
        </section>

        <section v-if="checkedIn.length > 0" class="flex flex-col gap-3">
            <h2 class="text-lg font-medium text-text">
                {{ $t('Checked in') }}
            </h2>

            <article
                v-for="entrant in checkedIn"
                :key="entrant.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface-raised p-4"
            >
                <div class="flex min-w-0 flex-col">
                    <span class="font-medium text-text">{{
                        entrant.teamName
                    }}</span>
                    <span class="text-sm text-success">
                        {{
                            $t('Checked in :time', {
                                time: formatDateTime(entrant.checkedInAt),
                            })
                        }}
                    </span>

                    <InputError :message="refusals[entrant.id]" />
                </div>

                <Button variant="outline" size="sm" @click="undo(entrant)">{{
                    $t('Undo check-in')
                }}</Button>
            </article>
        </section>
    </div>
</template>
