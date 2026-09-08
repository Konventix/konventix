<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TextLink from '@/components/TextLink.vue';
import { show as policy } from '@/routes/policies';

/**
 * Whose account this is (#184, ADR-0054). One account belongs to the whole
 * operator (ADR-0016), and until this notice existed nothing on a branded
 * login or registration page said so: two brands of the same house looked
 * like two unrelated sites that happened to accept the same password.
 *
 * Rendered by the three auth layouts rather than by each page, so login,
 * registration, password reset and the social-signup completion all show it
 * without a line of their own. Which text it shows follows from the page
 * Inertia is rendering — a page that creates an account says something the
 * law requires (Art. 13 DSGVO names the controller at collection time), and
 * one that only signs in does not.
 *
 * Renders nothing when `operatorPresence` is absent: that is the operator
 * console's own host, which resolves no brand and introduces nobody
 * (ADR-0029).
 */
const page = usePage();

const presence = computed(
    () =>
        page.props.operatorPresence as
            Konventix.Platform.Data.OperatorPresenceData | null | undefined,
);

/** The pages on which an account comes into existence. */
const creatingAccount = computed(() =>
    ['auth/Register', 'auth/CompleteSocialSignup'].includes(page.component),
);

/** Every brand, for the registration text: the account applies on all of them. */
const allBrands = computed(() => presence.value?.brands ?? []);

/** The others, for the login text: "it also applies on …". */
const otherBrands = computed(() =>
    allBrands.value.filter((brand) => !brand.current),
);

/**
 * A single-brand operator gets its name and no list — an empty "it also
 * applies on:" would be a sentence with nothing after the colon.
 */
const listed = computed(() =>
    creatingAccount.value ? allBrands.value : otherBrands.value,
);
const showList = computed(() => allBrands.value.length > 1);
</script>

<template>
    <section
        v-if="presence"
        class="text-center text-xs text-text-muted"
        aria-label="About this account"
    >
        <p>
            <template v-if="creatingAccount">
                You are creating an account with {{ presence.name }}.
                <template v-if="showList">
                    It applies on every site of {{ presence.name }}:
                </template>
            </template>
            <template v-else>
                Sign in with your {{ presence.name }} account.
                <template v-if="showList"> It also applies on: </template>
            </template>

            <template v-if="showList">
                <template v-for="(brand, index) in listed" :key="brand.id">
                    <a
                        v-if="brand.url"
                        :href="brand.url"
                        class="underline underline-offset-4 hover:text-text"
                    >
                        {{ brand.name }}
                    </a>
                    <span v-else>{{ brand.name }}</span>
                    <span v-if="index < listed.length - 1">, </span>
                </template>
                <span>.</span>
            </template>
        </p>

        <p v-if="creatingAccount" class="mt-1">
            {{ presence.legalName }} is responsible for processing your data —
            see the
            <TextLink :href="policy('privacy').url">privacy policy</TextLink>.
        </p>
    </section>
</template>
