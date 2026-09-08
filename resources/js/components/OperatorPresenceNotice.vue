<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    accountIntro,
    brandListIntro,
    controllerSentence,
    sharedAccountNotice,
} from '@/components/operatorPresenceText';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

/**
 * Whose account this is, and where (#184, #241, ADR-0054). One account
 * belongs to the whole operator (ADR-0016), and until this notice existed
 * nothing on a branded login or registration page said so: two brands of the
 * same house looked like two unrelated sites that happened to accept the same
 * password.
 *
 * #184 named the operator only, which left the visitor to work out which of
 * the listed sites they were standing on; #241 names the brand first,
 * because that is the name they arrived knowing. The brand's *mark* is not
 * repeated here — the auth layouts render it from the shell's `brand` prop,
 * above this notice.
 *
 * The sign-up surfaces also carry ADR-0054 point 6 on the page rather than
 * only in the mail: whoever already has an account from a sibling brand is
 * told, before they type anything, that signing in works. It is shown to
 * everyone, unconditionally — a notice that appeared only for an address
 * that already has an account would be the enumeration oracle ADR-0016
 * forbids. The wording lives in `operatorPresenceText.ts`, where it can be
 * tested.
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
 * (ADR-0029), and every page that is not an auth surface, which does not
 * ask for the prop at all.
 *
 * The Art. 13 sentence names the controller either way; it links to the
 * privacy policy only where the brand resolves a published one, because a
 * dead link from the sentence that tells someone who processes their data
 * is worse than no link (fix round 1).
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

const intro = computed(() =>
    presence.value === null || presence.value === undefined
        ? ''
        : accountIntro(
              presence.value.name,
              presence.value.brandName,
              creatingAccount.value,
          ),
);

const listIntro = computed(() =>
    presence.value === null || presence.value === undefined
        ? null
        : brandListIntro(
              presence.value.name,
              creatingAccount.value,
              allBrands.value.length,
          ),
);

/** ADR-0054 point 6, on the sign-up surfaces only. */
const sharedAccount = computed(() =>
    presence.value === null ||
    presence.value === undefined ||
    !creatingAccount.value
        ? null
        : sharedAccountNotice(presence.value.name, allBrands.value.length),
);

const controller = computed(() =>
    presence.value === null || presence.value === undefined
        ? ''
        : controllerSentence(presence.value.legalName),
);
</script>

<template>
    <section
        v-if="presence"
        class="text-center text-xs text-text-muted"
        aria-label="About this account"
    >
        <p>
            {{ intro }}
            <template v-if="showList && listIntro">
                {{ listIntro }}
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

        <p v-if="sharedAccount" class="mt-1">
            {{ sharedAccount }}
            <TextLink :href="login()">Sign in</TextLink>
        </p>

        <p v-if="creatingAccount" class="mt-1">
            {{ controller
            }}<template v-if="presence.privacyPolicyUrl">
                — see the
                <TextLink :href="presence.privacyPolicyUrl">
                    privacy policy
                </TextLink></template
            >.
        </p>
    </section>
</template>
