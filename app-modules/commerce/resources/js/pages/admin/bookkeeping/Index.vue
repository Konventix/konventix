<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BookOpenCheck, Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { datev, journal, settings as settingsRoute } from '@/routes/admin/bookkeeping';

/**
 * Monthly hand-over to the tax advisor (ADR-0040): a DATEV Buchungsstapel
 * for import plus a readable journal, one export per operator and month,
 * all brands in one file.
 */
type Props = {
    settings: {
        consultantNumber: number | null;
        clientNumber: number | null;
        glAccountLength: number;
        debtorAccount: string;
        revenueStandard: string;
        revenueReduced: string;
        revenueReverseCharge: string;
        revenueSmallBusiness: string;
    };
};

const props = defineProps<Props>();

const now = new Date();
const previousMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);

const year = ref(String(previousMonth.getFullYear()));
const month = ref(String(previousMonth.getMonth() + 1));

const years = Array.from({ length: 4 }, (_, i) => String(now.getFullYear() - i));
const months = Array.from({ length: 12 }, (_, i) => String(i + 1));

const period = computed(() => ({ year: year.value, month: month.value }));
const datevUrl = computed(() => datev({ query: period.value }).url);
const journalUrl = computed(() => journal({ query: period.value }).url);

const form = useForm({
    consultant_number: props.settings.consultantNumber,
    client_number: props.settings.clientNumber,
    gl_account_length: props.settings.glAccountLength,
    debtor_account: props.settings.debtorAccount,
    revenue_standard: props.settings.revenueStandard,
    revenue_reduced: props.settings.revenueReduced,
    revenue_reverse_charge: props.settings.revenueReverseCharge,
    revenue_small_business: props.settings.revenueSmallBusiness,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(settingsRoute().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Bookkeeping export" />

    <div class="flex flex-col gap-6 p-6 lg:p-8">
        <div class="flex items-center gap-3">
            <BookOpenCheck class="size-6 text-text-muted" />
            <h1 class="text-2xl font-semibold text-text">Bookkeeping export</h1>
        </div>

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>Monthly export</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p class="max-w-2xl text-sm text-text-muted">
                    One export per month, covering every brand of this
                    operator: invoices and credit notes only — payments are
                    booked by the advisor from the bank and provider
                    statements.
                </p>

                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-2">
                        <Label for="month">Month</Label>
                        <Select v-model="month">
                            <SelectTrigger id="month" class="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="m in months" :key="m" :value="m">{{ m }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="year">Year</Label>
                        <Select v-model="year">
                            <SelectTrigger id="year" class="w-32">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="y in years" :key="y" :value="y">{{ y }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <Button as-child variant="default">
                        <a :href="datevUrl">
                            <Download class="size-4" />
                            DATEV Buchungsstapel
                        </a>
                    </Button>
                    <Button as-child variant="outline">
                        <a :href="journalUrl">
                            <Download class="size-4" />
                            Journal (CSV)
                        </a>
                    </Button>
                </div>
            </CardContent>
        </Card>

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>DATEV settings</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="flex max-w-xl flex-col gap-4" @submit.prevent="submit">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-2">
                            <Label for="consultant_number">Beraternummer</Label>
                            <Input id="consultant_number" v-model="form.consultant_number" type="number" />
                            <InputError :message="form.errors.consultant_number" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="client_number">Mandantennummer</Label>
                            <Input id="client_number" v-model="form.client_number" type="number" />
                            <InputError :message="form.errors.client_number" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="gl_account_length">Sachkontenlänge</Label>
                            <Input id="gl_account_length" v-model="form.gl_account_length" type="number" min="4" max="8" />
                            <InputError :message="form.errors.gl_account_length" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="debtor_account">Sammeldebitor</Label>
                            <Input id="debtor_account" v-model="form.debtor_account" />
                            <InputError :message="form.errors.debtor_account" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="revenue_standard">Erlöse 19 %</Label>
                            <Input id="revenue_standard" v-model="form.revenue_standard" />
                            <InputError :message="form.errors.revenue_standard" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="revenue_reduced">Erlöse 7 %</Label>
                            <Input id="revenue_reduced" v-model="form.revenue_reduced" />
                            <InputError :message="form.errors.revenue_reduced" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="revenue_reverse_charge">Erlöse Reverse Charge</Label>
                            <Input id="revenue_reverse_charge" v-model="form.revenue_reverse_charge" />
                            <InputError :message="form.errors.revenue_reverse_charge" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="revenue_small_business">Erlöse §19 UStG</Label>
                            <Input id="revenue_small_business" v-model="form.revenue_small_business" />
                            <InputError :message="form.errors.revenue_small_business" />
                        </div>
                    </div>

                    <p class="text-xs text-text-muted">
                        Defaults are the SKR03 Automatikkonten (8400 / 8300 /
                        8336 / 8195) — confirm with the tax advisor, or enter
                        their SKR04 or custom accounts.
                    </p>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="form.processing">Save settings</Button>
                        <span v-if="form.isDirty" role="status" class="text-sm text-warning">
                            Unsaved changes
                        </span>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
