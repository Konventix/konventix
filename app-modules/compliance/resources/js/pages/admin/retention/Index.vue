<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    store as storeRule,
    update as updateRule,
} from '@/routes/admin/retention';

/**
 * How long each category of data may be kept.
 *
 * The row shows the cutoff a rule currently implies, not only its period:
 * "36 months" and "everything before 2023-08-17" are the same fact, and only
 * one of them can be checked at a glance against what is actually in the
 * database.
 *
 * Nothing here deletes anything. Compliance publishes the rule; the module
 * that holds the data acts on it when the sweep signals.
 */
type RuleRow = {
    id: string;
    dataCategory: string;
    retentionMonths: number;
    clockStart: Konventix.Compliance.Enums.RetentionClockStart;
    description: string | null;
    active: boolean;
    brandName: string | null;
    brandId: string | null;
    currentCutoff: string | null;
};

const props = defineProps<{
    rules: RuleRow[];
    brands: { id: string; name: string }[];
    clockStarts: Konventix.Compliance.Enums.RetentionClockStart[];
    clocks: number;
}>();

const open = ref(false);

const form = useForm({
    brand_id: null as string | null,
    data_category: '',
    retention_months: 12,
    clock_start: 'record_age',
    description: '',
    active: true,
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        brand_id: data.brand_id === '' ? null : data.brand_id,
        description: data.description === '' ? null : data.description,
    })).post(storeRule().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}

function toggleActive(rule: RuleRow): void {
    useForm({
        brand_id: rule.brandId,
        data_category: rule.dataCategory,
        retention_months: rule.retentionMonths,
        clock_start: rule.clockStart,
        description: rule.description,
        active: !rule.active,
    }).put(updateRule(rule.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Retention" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Retention"
            description="How long each category of data may be kept — published here, discharged by the module that holds it"
        >
            <Dialog v-model:open="open">
                <DialogTrigger as-child>
                    <Button>
                        <Plus class="size-4" />
                        New rule
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New retention rule</DialogTitle>
                    </DialogHeader>
                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="rule-brand">Brand</Label>
                            <select
                                id="rule-brand"
                                v-model="form.brand_id"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option :value="null">
                                    All brands (operator-wide)
                                </option>
                                <option
                                    v-for="brand in props.brands"
                                    :key="brand.id"
                                    :value="brand.id"
                                >
                                    {{ brand.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.brand_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rule-category">Data category</Label>
                            <Input
                                id="rule-category"
                                v-model="form.data_category"
                                placeholder="admission.check-ins"
                            />
                            <p class="text-xs text-text-muted">
                                Dot-namespaced by the owning module. The module
                                recognises its own categories; nothing here
                                knows which tables they cover.
                            </p>
                            <InputError :message="form.errors.data_category" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="rule-months">Months</Label>
                                <Input
                                    id="rule-months"
                                    v-model="form.retention_months"
                                    type="number"
                                    min="1"
                                />
                                <InputError
                                    :message="form.errors.retention_months"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="rule-clock">Clock starts</Label>
                                <select
                                    id="rule-clock"
                                    v-model="form.clock_start"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option
                                        v-for="clock in props.clockStarts"
                                        :key="clock"
                                        :value="clock"
                                    >
                                        {{ clock }}
                                    </option>
                                </select>
                                <InputError
                                    :message="form.errors.clock_start"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="rule-description">Description</Label>
                            <Input
                                id="rule-description"
                                v-model="form.description"
                            />
                            <InputError :message="form.errors.description" />
                        </div>
                        <Button type="submit" :disabled="form.processing">
                            Create
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminPageHeader>

        <p class="text-sm text-text-muted">
            {{ props.clocks }} concluded event{{
                props.clocks === 1 ? '' : 's'
            }}
            currently counting for event-scoped rules.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <Card v-for="rule in props.rules" :key="rule.id">
                <CardHeader>
                    <CardTitle>{{ rule.dataCategory }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p class="text-sm">
                        {{ rule.retentionMonths }} months from
                        {{
                            rule.clockStart === 'record_age'
                                ? 'each record'
                                : 'the end of the event'
                        }}
                    </p>
                    <p
                        v-if="rule.currentCutoff"
                        class="text-xs text-text-muted"
                    >
                        Next sweep removes everything before
                        {{ rule.currentCutoff }}.
                    </p>
                    <p class="text-xs text-text-muted">
                        {{ rule.brandName ?? 'All brands' }}
                        <template v-if="rule.description">
                            · {{ rule.description }}
                        </template>
                    </p>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="rule.active"
                            :aria-label="`Rule ${rule.dataCategory} active`"
                            @update:model-value="() => toggleActive(rule)"
                        />
                        Active
                    </label>
                </CardContent>
            </Card>
        </div>

        <p v-if="props.rules.length === 0" class="text-sm text-text-muted">
            No retention rules.
        </p>
    </div>
</template>
