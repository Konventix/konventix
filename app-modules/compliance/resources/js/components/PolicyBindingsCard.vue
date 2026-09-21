<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    destroy as destroyBinding,
    store as storeBinding,
} from '@/routes/admin/policy-bindings';
import { bindingRuleSentence, groupBindings } from './policyBindings';
import type { BindingKind, BindingRow } from './policyBindings';

/**
 * "Where this applies" — the policy editor's binding card (ADR-0066 §9.2).
 *
 * Two sentences carry the whole feature. The empty state is the meaningful
 * one (D-CPL-04): a text with no bindings applies everywhere this brand
 * sells, which is what every text on every existing install does, so the card
 * says it out loud rather than looking like a list somebody forgot to fill.
 * And as soon as a second kind is bound, the card prints the matching rule in
 * words (D-CPL-03) — OR within a kind, AND across kinds — because that rule
 * narrows, and an operator who read it as a flat OR would publish a text that
 * is asked for nowhere.
 *
 * A target Platform's registry cannot name renders as
 * `Unknown (event · 01HX…)` beside its own *Remove* button: a dangling
 * binding is not an error, it simply never matches, and the console is the
 * only place it can be cleaned up.
 *
 * The picker is only as complete as the modules that registered with
 * `TargetDirectory`, which its own empty state says.
 */
type Option = Konventix.Platform.Data.TargetOptionData;

const props = defineProps<{
    policyId: string;
    bindings: BindingRow[];
    options: Option[];
    kinds: BindingKind[];
    canManage: boolean;
}>();

const form = useForm({ kind: '', target_key: '' });

const groups = computed(() => groupBindings(props.bindings, props.kinds));
const ruleSentence = computed(() =>
    bindingRuleSentence(props.bindings, props.kinds),
);

const optionsForKind = computed<Option[]>(() =>
    props.options.filter((option) => option.kind === form.kind),
);

function submit(): void {
    form.post(storeBinding(props.policyId).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(binding: BindingRow): void {
    router.delete(destroyBinding(binding.id).url, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Where this applies</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <p
                v-if="props.bindings.length === 0"
                class="text-sm text-text-muted"
            >
                No bindings: this text applies everywhere this brand sells.
            </p>

            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="group in groups"
                    :key="group.kind"
                    class="flex flex-col gap-2"
                >
                    <h3 class="text-xs font-semibold text-text-muted uppercase">
                        {{ group.label }}
                    </h3>
                    <ul class="flex flex-wrap gap-2">
                        <li
                            v-for="binding in group.bindings"
                            :key="binding.id"
                            class="flex items-center gap-2 rounded-full border border-border bg-surface-raised px-3 py-1 text-sm"
                        >
                            <span
                                :class="
                                    binding.label === null
                                        ? 'text-warning'
                                        : undefined
                                "
                            >
                                {{ binding.display }}
                            </span>
                            <Button
                                v-if="props.canManage"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-5"
                                @click="remove(binding)"
                            >
                                <X class="size-3" />
                                <span class="sr-only"
                                    >Remove {{ binding.display }}</span
                                >
                            </Button>
                        </li>
                    </ul>
                </div>

                <p v-if="ruleSentence" class="text-sm text-text-muted">
                    {{ ruleSentence }}
                </p>
            </div>

            <form
                v-if="props.canManage"
                class="flex flex-col gap-3 border-t border-border pt-4"
                @submit.prevent="submit"
            >
                <p
                    v-if="props.options.length === 0"
                    class="text-sm text-text-muted"
                >
                    No module offers anything to bind to yet.
                </p>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="policy-binding-kind">Kind</Label>
                        <Select
                            v-model="form.kind"
                            @update:model-value="form.target_key = ''"
                        >
                            <SelectTrigger id="policy-binding-kind">
                                <SelectValue placeholder="Choose a kind" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="kind in props.kinds"
                                    :key="kind.value"
                                    :value="kind.value"
                                >
                                    {{ kind.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.kind" />
                    </div>

                    <div v-if="form.kind !== ''" class="grid gap-2">
                        <Label for="policy-binding-target">Target</Label>
                        <Select v-model="form.target_key">
                            <SelectTrigger id="policy-binding-target">
                                <SelectValue placeholder="Choose a target" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in optionsForKind"
                                    :key="option.id"
                                    :value="option.id"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.target_key" />
                    </div>

                    <div>
                        <Button
                            type="submit"
                            :disabled="
                                form.processing ||
                                form.kind === '' ||
                                form.target_key === ''
                            "
                        >
                            <Plus class="size-4" />
                            Add binding
                        </Button>
                    </div>
                </template>
            </form>
        </CardContent>
    </Card>
</template>
