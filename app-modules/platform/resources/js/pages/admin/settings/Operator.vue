<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime, withDateTimeSettings } from '@/lib/datetime';
import { update } from '@/routes/admin/settings/operator';

/**
 * The operator running this instance (ADR-0001), and the legal identity every
 * invoice is issued under (ADR-0030).
 *
 * There is no operator picker and no id in the URL: the admin host resolves
 * exactly one operator, and that is the one this page edits.
 *
 * The host itself is not editable here. Changing the address the console
 * answers on, from a page served on that address, is how somebody locks
 * themselves out — it belongs with provisioning.
 */
const props = defineProps<{
    operator: Konventix.Platform.Data.OperatorSettingsData;
    /**
     * The environment has closed signups for the whole instance
     * (`SIGNUPS_ENABLED=false`). The switch below still saves, but cannot
     * re-open them — so the page says so beside it.
     */
    signupsForcedClosed: boolean;
}>();

// Nullable columns arrive as null and bind to text inputs as empty strings —
// an <input> has no concept of null. `UpdateOperatorSettings` turns them back
// into nulls on the way in, so a cleared field ends up null in the database
// rather than an empty string that reads as "set to nothing".
const form = useForm({
    name: props.operator.name,
    legalName: props.operator.legalName,
    contactEmail: props.operator.contactEmail ?? '',
    addressLine: props.operator.addressLine ?? '',
    postalCode: props.operator.postalCode ?? '',
    city: props.operator.city ?? '',
    countryCode: props.operator.countryCode,
    vatId: props.operator.vatId ?? '',
    taxNumber: props.operator.taxNumber ?? '',
    smallBusinessScheme: props.operator.smallBusinessScheme,
    signupsOpen: props.operator.signupsOpen,
    dateFormat: props.operator.dateFormat,
    timeFormat: props.operator.timeFormat,
    locale: props.operator.locale,
});

/** The locales this instance ships, each named in its own language. */
const page = usePage();

/**
 * A sample rendered with whatever is currently picked (#242), so the choice
 * is read as a date rather than as a pattern. It is the same helper every
 * page and every table uses, driven off the form rather than off the saved
 * setting — the preview moves before the save does.
 */
const sample = computed(() =>
    withDateTimeSettings(
        { dateFormat: form.dateFormat, timeFormat: form.timeFormat },
        () => formatDateTime('2026-09-08T14:05:00+00:00'),
    ),
);

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Operator settings')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Operator settings')"
            :description="
                $t(
                    'The legal entity running this instance. Its identity appears on every invoice.',
                )
            "
        />

        <form class="grid max-w-3xl gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Identity') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="name">{{ $t('Display name') }}</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="legalName">{{ $t('Legal name') }}</Label>
                        <Input
                            id="legalName"
                            v-model="form.legalName"
                            required
                        />
                        <InputError :message="form.errors.legalName" />
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'Printed as the seller on every invoice. Settlement is refused for a brand whose operator has none.',
                                )
                            }}
                        </p>
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="contactEmail">{{
                            $t('Contact address')
                        }}</Label>
                        <Input
                            id="contactEmail"
                            v-model="form.contactEmail"
                            type="email"
                        />
                        <InputError :message="form.errors.contactEmail" />
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'Where operator-facing notices go — a statutory deadline needs one owner, not a distribution list.',
                                )
                            }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Registered address') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="addressLine">{{
                            $t('Street and number')
                        }}</Label>
                        <Input id="addressLine" v-model="form.addressLine" />
                        <InputError :message="form.errors.addressLine" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="postalCode">{{ $t('Postal code') }}</Label>
                        <Input id="postalCode" v-model="form.postalCode" />
                        <InputError :message="form.errors.postalCode" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="city">{{ $t('City') }}</Label>
                        <Input id="city" v-model="form.city" />
                        <InputError :message="form.errors.city" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="countryCode">{{
                            $t('Country code')
                        }}</Label>
                        <Input
                            id="countryCode"
                            v-model="form.countryCode"
                            maxlength="2"
                            required
                        />
                        <InputError :message="form.errors.countryCode" />
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'Two letters, ISO 3166-1 — what ZUGFeRD expects.',
                                )
                            }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Tax') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="vatId">{{
                            $t('VAT identification number')
                        }}</Label>
                        <Input id="vatId" v-model="form.vatId" />
                        <InputError :message="form.errors.vatId" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="taxNumber">{{ $t('Tax number') }}</Label>
                        <Input id="taxNumber" v-model="form.taxNumber" />
                        <InputError :message="form.errors.taxNumber" />
                    </div>

                    <div class="flex items-start gap-3 sm:col-span-2">
                        <Checkbox
                            id="smallBusinessScheme"
                            v-model="form.smallBusinessScheme"
                        />
                        <div class="grid gap-1">
                            <Label for="smallBusinessScheme">
                                {{ $t('Small-business scheme (§19 UStG)') }}
                            </Label>
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'No VAT is shown on invoices, and each carries the §19 note instead. This is an operator-level election in law, so it applies to every brand.',
                                    )
                                }}
                            </p>
                        </div>
                        <InputError
                            :message="form.errors.smallBusinessScheme"
                        />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Accounts') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <div class="flex items-start gap-3">
                        <Checkbox id="signupsOpen" v-model="form.signupsOpen" />
                        <div class="grid gap-1">
                            <Label for="signupsOpen">
                                {{ $t('Let visitors create accounts') }}
                            </Label>
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Off, the sign-up form and the Steam sign-up step tell a stranger that sign-up is closed. People who already have an account log in as before. An account is the person’s, so this applies on every brand host at once.',
                                    )
                                }}
                            </p>
                            <!--
                                Always rendered so a screen reader has a live
                                region to observe when the environment's cap
                                is what decides (ADR-0055); only its text
                                comes and goes.
                            -->
                            <p
                                class="text-xs text-warning"
                                role="status"
                                data-test="signups-forced-closed"
                            >
                                <template v-if="signupsForcedClosed">
                                    {{
                                        $t(
                                            'Sign-up is closed by this instance’s configuration (SIGNUPS_ENABLED). The switch is saved, but cannot open it.',
                                        )
                                    }}
                                </template>
                            </p>
                        </div>
                        <InputError :message="form.errors.signupsOpen" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Language') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <Label for="locale">{{ $t('Default language') }}</Label>
                    <select
                        id="locale"
                        v-model="form.locale"
                        required
                        aria-describedby="locale-hint"
                        class="h-9 max-w-xs rounded-md border border-border bg-surface px-3 text-sm"
                    >
                        <option
                            v-for="option in page.props.locales"
                            :key="option.code"
                            :value="option.code"
                        >
                            {{ option.name }}
                        </option>
                    </select>
                    <p id="locale-hint" class="text-xs text-text-muted">
                        {{
                            $t(
                                'What every brand speaks unless it picks its own language, and what a person reads unless they pick theirs.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.locale" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Dates and times') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <p class="text-xs text-text-muted sm:col-span-2">
                        {{
                            $t(
                                'How this instance writes a date and a time — on every console page, in every mail and on every invoice. Each person still reads them in their own timezone, which they set on their profile.',
                            )
                        }}
                    </p>

                    <div class="grid gap-2">
                        <Label for="dateFormat">{{ $t('Date format') }}</Label>
                        <select
                            id="dateFormat"
                            v-model="form.dateFormat"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option value="DD.MM.YYYY">
                                {{ $t('DD.MM.YYYY') }}
                            </option>
                            <option value="MM/DD/YYYY">
                                {{ $t('MM/DD/YYYY') }}
                            </option>
                            <option value="YYYY-MM-DD">
                                {{ $t('YYYY-MM-DD') }}
                            </option>
                        </select>
                        <InputError :message="form.errors.dateFormat" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="timeFormat">{{ $t('Time format') }}</Label>
                        <select
                            id="timeFormat"
                            v-model="form.timeFormat"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option value="24h">
                                {{ $t('24-hour (14:05)') }}
                            </option>
                            <option value="12h">
                                {{ $t('12-hour (2:05 PM)') }}
                            </option>
                        </select>
                        <InputError :message="form.errors.timeFormat" />
                    </div>

                    <!--
                        A live region, because the only thing that changes when
                        either select moves is the sample: without it a screen
                        reader hears the option it picked and never the result
                        (ADR-0055). The element is always rendered and only its
                        text changes, which is what a live region announces.
                    -->
                    <p
                        class="text-sm text-text-muted sm:col-span-2"
                        role="status"
                        data-test="date-format-sample"
                    >
                        {{ $t('A timestamp reads as') }}
                        <span class="font-medium text-text">{{ sample }}</span>
                        .
                    </p>
                </CardContent>
            </Card>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? $t('Saving…') : $t('Save changes') }}
                </Button>
                <!--
                    The region is always in the document and only its text
                    appears: a `role="status"` inserted in the same tick as its
                    own content is announced by essentially no screen reader,
                    because there was no live region to observe when the text
                    arrived (ADR-0055).
                -->
                <span role="status" class="text-sm text-warning">
                    <template v-if="form.isDirty">{{
                        $t('Unsaved changes')
                    }}</template>
                </span>
            </div>
        </form>
    </div>
</template>
