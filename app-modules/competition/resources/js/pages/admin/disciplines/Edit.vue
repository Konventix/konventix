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
 * The game id is typed by hand on purpose: GameOps publishes no catalogue
 * event yet, so there is nothing to build a picker from (ADR-0024). Null for
 * every kind that is not a video game.
 */
type Props = {
    discipline: Konventix.Competition.Data.DisciplineData & {
        id: string;
        brandName: string;
    };
    options: { kind: string[] };
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
    <Head :title="`${props.discipline.name} — Discipline`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.discipline.name"
            :description="props.discipline.brandName"
        >
            <span
                v-if="frozen"
                class="inline-flex rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning"
            >
                {{ props.competitions }} competition(s) — team size frozen
            </span>
            <Button variant="outline" as-child>
                <Link :href="disciplinesIndex().url">
                    <ArrowLeft class="size-4" />
                    All disciplines
                </Link>
            </Button>
        </AdminPageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Identity</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="discipline-name">Name</Label>
                            <Input id="discipline-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-slug">Slug</Label>
                            <Input id="discipline-slug" v-model="form.slug" />
                            <InputError :message="form.errors.slug" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-kind">Kind</Label>
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
                                    {{ value }}
                                </option>
                            </select>
                            <InputError :message="form.errors.kind" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-game">GameOps game id</Label>
                            <Input
                                id="discipline-game"
                                v-model="form.gameId"
                                placeholder="ULID, video games only"
                            />
                            <p class="text-xs text-text-muted">
                                Leave empty for every kind that is not a video
                                game.
                            </p>
                            <InputError :message="form.errors.gameId" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Contest defaults</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="discipline-team-size">Team size</Label>
                            <Input
                                id="discipline-team-size"
                                v-model.number="form.teamSize"
                                type="number"
                                min="1"
                                :disabled="frozen"
                            />
                            <p class="text-xs text-text-muted">
                                1 is a solo contest — a team of one. A roster
                                below this is held as incomplete until signup
                                closes.
                            </p>
                            <InputError :message="form.errors.teamSize" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="discipline-minutes">
                                Average match minutes
                            </Label>
                            <Input
                                id="discipline-minutes"
                                v-model.number="form.avgMatchMinutes"
                                type="number"
                                min="1"
                            />
                            <p class="text-xs text-text-muted">
                                The duration hint the schedule reads; nothing
                                enforces it.
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
                            Draws are possible
                        </label>
                        <InputError :message="form.errors.allowDraws" />
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Save
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>
            </div>
        </form>
    </div>
</template>
