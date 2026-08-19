<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/linked-accounts';
import { destroy } from '@/routes/linked-accounts';
import { redirect as socialRedirect } from '@/routes/social';

/**
 * Every way into this account that is not an email and a password — including
 * the ones not connected yet, because "you could connect Steam" is as much a
 * thing this page has to say as "Steam is connected".
 */
const props = defineProps<{
    accounts: Konventix.Identity.Data.LinkedAccountData[];
    hasPassword: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Linked accounts', href: edit() }],
    },
});
</script>

<template>
    <Head title="Linked accounts" />

    <h1 class="sr-only">Linked accounts</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Linked accounts"
            description="Connect an account you already have, and use it to sign in."
        />

        <ul class="divide-y divide-border rounded-lg border border-border">
            <li
                v-for="account in props.accounts"
                :key="account.provider"
                class="flex flex-wrap items-center justify-between gap-4 p-4"
            >
                <div class="flex items-center gap-3">
                    <img
                        v-if="account.avatarUrl"
                        :src="account.avatarUrl"
                        alt=""
                        class="size-10 rounded-full"
                    />
                    <div>
                        <p class="font-medium text-text">{{ account.label }}</p>
                        <p
                            v-if="account.linked"
                            class="text-xs text-text-muted"
                        >
                            {{ account.nickname ?? 'Connected' }}
                            <span v-if="account.linkedAt">
                                · since {{ account.linkedAt }}
                            </span>
                        </p>
                        <p
                            v-else
                            class="text-xs text-text-muted"
                        >
                            Not connected
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!--
                        Only shown when unlinking is impossible, and it says
                        why: without a password this is the only way back in,
                        so removing it would end the account rather than
                        tidy it.
                    -->
                    <p
                        v-if="account.linked && !account.canUnlink"
                        class="max-w-xs text-xs text-text-muted"
                    >
                        Set a password first — this is currently the only way
                        into your account.
                    </p>

                    <Form
                        v-if="account.linked && account.canUnlink"
                        v-bind="destroy(account.provider)"
                        v-slot="{ errors, processing }"
                    >
                        <InputError :message="errors.provider" />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                        >
                            Disconnect
                        </Button>
                    </Form>

                    <Button
                        v-if="!account.linked"
                        as-child
                        variant="outline"
                    >
                        <a :href="socialRedirect(account.provider).url">
                            Connect
                        </a>
                    </Button>
                </div>
            </li>
        </ul>

        <p
            v-if="!props.hasPassword"
            class="text-sm text-text-muted"
        >
            This account has no password yet. You can set one from the
            security settings to add a second way to sign in.
        </p>
    </div>
</template>
