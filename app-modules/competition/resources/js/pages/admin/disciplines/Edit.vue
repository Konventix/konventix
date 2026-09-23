<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    index as disciplinesIndex,
    update as updateDiscipline,
} from '@/routes/admin/disciplines';
import { disciplineKindLabel } from '../../../components/public/labels';

/**
 * One discipline (ADR-0024, ADR-0062): its identity and the contest
 * defaults it hands every competition — team size, the duration hint the
 * later schedule estimator reads, and whether a draw is a possible result.
 *
 * Team size freezes once a competition runs this discipline: it is the
 * ceiling a roster is checked against and the number that decides whether an
 * entrant is incomplete, so changing it mid-event would re-classify teams
 * that were complete when they entered. `UpdateDiscipline` is the authority
 * and refuses it server-side; the form only disables what it can see.
 *
 * The linked-account requirement (design §5.6) does not freeze: switching it
 * changes no entrant or roster that already exists, and it applies at the
 * next entry or join.
 *
 * The game id is typed by hand on purpose: GameOps publishes no catalogue
 * event yet, so there is nothing to build a picker from (ADR-0024). Null for
 * every kind that is not a video game.
 */
type Props = {
    discipline: Konventix.Competition.Data.DisciplineData & {
        id: string;
        brandName: string;
    };
    options: {
        kind: string[];
        linkedProvider: { value: string; label: string }[];
    };
    competitions: number;
};

const props = defineProps<Props>();

const form = useForm({
    kind: props.discipline.kind,
    name: props.discipline.name,
    slug: props.discipline.slug,
    teamSize: props.discipline.teamSize,
    avgMatchMinutes: props.discipline.avgMatchMinutes,
    allowDraws: props.discipline.allowDraws,
    gameId: props.discipline.gameId ?? '',
    requiresLinkedProvider: (props.discipline.requiresLinkedProvider ??
        null) as string | null,
});

useUnsavedChanges(() => form.isDirty);

const frozen = props.competitions > 0;

function submit(): void {
    form.transform((data) => ({
        ...data,
        gameId: data.gameId === '' ? null : data.gameId,
    })).put(updateDiscipline(props.discipline.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="$t(':name — Discipline', { name: props.discipline.name })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.discipline.name"
            :description="props.discipline.brandName"
        >
            <span
                v-if="frozen"
                class="inline-flex rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning"
            >
                {{
                    $tChoice(
                        '{1} :count competition — team size frozen|[0,*] :count competitions — team size frozen',
                        props.competitions,
                    )
                }}
            </span>
            <Button variant="outline" as-child>
                <Link :href="disciplinesIndex().url">
                    <ArrowLeft class="size-4" />
                    {{ $t('All disciplines') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Identity') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="discipline-name">{{
                                $t('Name')
                            }}</Label>
                            <Input id="discipline-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-slug">{{
                                $t('Slug')
                            }}</Label>
                            <Input id="discipline-slug" v-model="form.slug" />
                            <InputError :message="form.errors.slug" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-kind">{{
                                $t('Kind')
                            }}</Label>
                            <select
                                id="discipline-kind"
                                v-model="form.kind"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="value in props.options.kind"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ disciplineKindLabel(value) }}
                                </option>
                            </select>
                            <InputError :message="form.errors.kind" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-game">{{
                                $t('GameOps game id')
                            }}</Label>
                            <Input
                                id="discipline-game"
                                v-model="form.gameId"
                                :placeholder="$t('ULID, video games only')"
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Leave empty for every kind that is not a video game.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.gameId" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Contest defaults') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="discipline-team-size">{{
                                $t('Team size')
                            }}</Label>
                            <Input
                                id="discipline-team-size"
                                v-model.number="form.teamSize"
                                type="number"
                                min="1"
                                :disabled="frozen"
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        '1 is a solo contest — a team of one. A roster below this is held as incomplete until signup closes.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.teamSize" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-minutes">
                                {{ $t('Average match minutes') }}
                            </Label>
                            <Input
                                id="discipline-minutes"
                                v-model.number="form.avgMatchMinutes"
                                type="number"
                                min="1"
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'The duration hint the schedule reads; nothing enforces it.',
                                    )
                                }}
                            </p>
                            <InputError
                                :message="form.errors.avgMatchMinutes"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.allowDraws"
                                type="checkbox"
                                class="size-4 rounded border-border"
                            />
                            {{ $t('Draws are possible') }}
                        </label>
                        <InputError :message="form.errors.allowDraws" />
                        <div class="grid gap-2">
                            <Label for="discipline-linked-provider">
                                {{ $t('Required linked account') }}
                            </Label>
                            <select
                                id="discipline-linked-provider"
                                v-model="form.requiresLinkedProvider"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option :value="null">{{ $t('None') }}</option>
                                <option
                                    v-for="provider in props.options
                                        .linkedProvider"
                                    :key="provider.value"
                                    :value="provider.value"
                                >
                                    {{ provider.label }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Everyone who enters or joins a team must have linked this account. Switching it on does not touch teams that are already in; it applies to the next entry or join.',
                                    )
                                }}
                            </p>
                            <InputError
                                :message="form.errors.requiresLinkedProvider"
                            />
                        </div>
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ $t('Save') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </div>
        </form>
    </div>
</template>
