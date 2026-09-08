<script setup lang="ts">
import { Form, Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MembershipImageController from '@/actions/Konventix/Identity/Http/Controllers/MembershipImageController';
import ProfileController from '@/actions/Konventix/Identity/Http/Controllers/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { browserTimezone } from '@/lib/datetime';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const { profile, membershipImages, steamAvatarUrl } = defineProps<{
    profile: {
        name: string;
        email: string;
        username: string;
        tagline: string | null;
        statusEmoji: string | null;
        socialLinks: Record<string, string>;
        visibility: 'public' | 'internal' | 'event';
        timezone: string | null;
    };
    membershipImages: {
        avatarUrl: string | null;
        bannerUrl: string | null;
    };
    steamAvatarUrl: string | null;
}>();

/**
 * Every IANA zone the browser's own database knows (#243). Read from `Intl`
 * rather than shipped as a list: a hard-coded one goes stale the next time a
 * country moves its clocks, and the server validates against PHP's database
 * anyway. Older engines without `supportedValuesOf` still get their own zone
 * and UTC, which is enough to keep the form submittable.
 *
 * Server-side there is no list at all, only the stored value: node's ICU and
 * the browser's do not ship the same zone table, and rendering one on the
 * server and the other on hydration is a guaranteed mismatch on this page.
 */
const timezones = computed<string[]>(() => {
    if (typeof window === 'undefined') {
        return profile.timezone === null ? [] : [profile.timezone];
    }

    const supported =
        typeof Intl.supportedValuesOf === 'function'
            ? Intl.supportedValuesOf('timeZone')
            : ['UTC'];

    // The stored value and the browser's own are always offerable, even when
    // the browser has never heard of one of them.
    return [
        ...new Set(
            [...supported, browserTimezone(), profile.timezone].filter(
                (zone): zone is string => zone !== null,
            ),
        ),
    ].sort();
});

// The empty string is the "no zone of my own" choice, which the server stores
// as null (#243). It is the default an account starts with, not a missing
// value: every timestamp is then read on this device's clock.
const timezone = ref(profile.timezone ?? '');

// Named separately so the template reads it once rather than calling into
// `Intl` on every re-render.
const browserZone = computed(() => browserTimezone());

const avatarForm = useForm<{ file: File | null }>({ file: null });
const bannerForm = useForm<{ file: File | null }>({ file: null });
const page = usePage();

function submitAvatar(event: Event): void {
    const input = event.target as HTMLInputElement;
    avatarForm.file = input.files?.[0] ?? null;

    if (avatarForm.file === null) {
        return;
    }

    avatarForm.post(MembershipImageController.storeAvatar().url, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            avatarForm.reset('file');
            input.value = '';
        },
    });
}

function copySteamAvatar(): void {
    router.post(
        MembershipImageController.copySteam().url,
        {},
        { preserveScroll: true },
    );
}

function submitBanner(event: Event): void {
    const input = event.target as HTMLInputElement;
    bannerForm.file = input.files?.[0] ?? null;

    if (bannerForm.file === null) {
        return;
    }

    bannerForm.post(MembershipImageController.storeBanner().url, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            bannerForm.reset('file');
            input.value = '';
        },
    });
}
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="profile.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="profile.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="username">Username</Label>
                <Input
                    id="username"
                    class="mt-1 block w-full"
                    name="username"
                    :default-value="profile.username"
                    required
                    autocomplete="off"
                    placeholder="ada-lovelace"
                />
                <p class="text-sm text-text-muted">
                    Lowercase letters, numbers and hyphens only — starts and
                    ends with a letter or number.
                </p>
                <InputError class="mt-2" :message="errors.username" />
            </div>

            <div class="grid gap-2">
                <Label for="tagline">Tagline</Label>
                <Input
                    id="tagline"
                    class="mt-1 block w-full"
                    name="tagline"
                    :default-value="profile.tagline ?? ''"
                    maxlength="140"
                    placeholder="A short line about you"
                />
                <InputError class="mt-2" :message="errors.tagline" />
            </div>

            <div class="grid gap-2">
                <Label for="statusEmoji">Status emoji</Label>
                <Input
                    id="statusEmoji"
                    class="mt-1 block w-32"
                    name="statusEmoji"
                    :default-value="profile.statusEmoji ?? ''"
                    placeholder="🚀"
                />
                <p class="text-sm text-text-muted">A single emoji.</p>
                <InputError class="mt-2" :message="errors.statusEmoji" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="text-sm leading-none font-medium">
                    Who can see your profile page
                </legend>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="visibility"
                        value="public"
                        class="mt-1 size-4 accent-accent"
                        :checked="profile.visibility === 'public'"
                    />
                    <span>
                        <span class="block">Public</span>
                        <span class="block text-text-muted"
                            >Anyone, even without an account</span
                        >
                    </span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="visibility"
                        value="internal"
                        class="mt-1 size-4 accent-accent"
                        :checked="profile.visibility === 'internal'"
                    />
                    <span>
                        <span class="block">Internal</span>
                        <span class="block text-text-muted"
                            >Signed-in members of this brand</span
                        >
                    </span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="visibility"
                        value="event"
                        class="mt-1 size-4 accent-accent"
                        :checked="profile.visibility === 'event'"
                    />
                    <span>
                        <span class="block">Event only</span>
                        <span class="block text-text-muted"
                            >People confirmed for the same event</span
                        >
                    </span>
                </label>
                <InputError class="mt-2" :message="errors.visibility" />
            </fieldset>

            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <select
                    id="timezone"
                    v-model="timezone"
                    name="timezone"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    :aria-invalid="errors.timezone ? true : undefined"
                    aria-describedby="timezone-hint timezone-error"
                >
                    <option value="">Browser ({{ browserZone }})</option>
                    <option v-for="zone in timezones" :key="zone" :value="zone">
                        {{ zone }}
                    </option>
                </select>
                <p id="timezone-hint" class="text-sm text-text-muted">
                    Every date and time on this site is shown in this zone.
                    Leave it on
                    <span class="font-medium">Browser</span> to follow whichever
                    device you are reading on.
                </p>
                <InputError
                    id="timezone-error"
                    class="mt-2"
                    :message="errors.timezone"
                />
            </div>

            <div class="grid gap-4">
                <p class="text-sm leading-none font-medium">Social links</p>

                <div class="grid gap-2">
                    <Label for="socialLinks.steam">Steam</Label>
                    <Input
                        id="socialLinks.steam"
                        class="mt-1 block w-full"
                        name="socialLinks[steam]"
                        :default-value="profile.socialLinks.steam ?? ''"
                        placeholder="Steam handle"
                    />
                    <p class="text-sm text-text-muted">
                        Your custom URL name, not the full link: 2-32 letters,
                        digits, - or _.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.steam']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="socialLinks.discord">Discord</Label>
                    <Input
                        id="socialLinks.discord"
                        class="mt-1 block w-full"
                        name="socialLinks[discord]"
                        :default-value="profile.socialLinks.discord ?? ''"
                        placeholder="Discord username"
                    />
                    <p class="text-sm text-text-muted">
                        Username only: 2-32 lowercase letters, digits, . or _.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.discord']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="socialLinks.twitch">Twitch</Label>
                    <Input
                        id="socialLinks.twitch"
                        class="mt-1 block w-full"
                        name="socialLinks[twitch]"
                        :default-value="profile.socialLinks.twitch ?? ''"
                        placeholder="Twitch channel"
                    />
                    <p class="text-sm text-text-muted">
                        Channel name: 3-25 letters, digits or _.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.twitch']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="socialLinks.youtube">YouTube</Label>
                    <Input
                        id="socialLinks.youtube"
                        class="mt-1 block w-full"
                        name="socialLinks[youtube]"
                        :default-value="profile.socialLinks.youtube ?? ''"
                        placeholder="@channel"
                    />
                    <p class="text-sm text-text-muted">
                        Handle: 3-30 letters, digits, . _ or -; the @ is
                        optional.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.youtube']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="socialLinks.x">X</Label>
                    <Input
                        id="socialLinks.x"
                        class="mt-1 block w-full"
                        name="socialLinks[x]"
                        :default-value="profile.socialLinks.x ?? ''"
                        placeholder="Handle"
                    />
                    <p class="text-sm text-text-muted">
                        Handle without the @: 1-15 letters, digits or _.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.x']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="socialLinks.instagram">Instagram</Label>
                    <Input
                        id="socialLinks.instagram"
                        class="mt-1 block w-full"
                        name="socialLinks[instagram]"
                        :default-value="profile.socialLinks.instagram ?? ''"
                        placeholder="Instagram handle"
                    />
                    <p class="text-sm text-text-muted">
                        Username: 1-30 letters, digits, . or _.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.instagram']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="socialLinks.website">Website</Label>
                    <Input
                        id="socialLinks.website"
                        type="url"
                        class="mt-1 block w-full"
                        name="socialLinks[website]"
                        :default-value="profile.socialLinks.website ?? ''"
                        placeholder="https://example.com"
                    />
                    <p class="text-sm text-text-muted">
                        Full address including https://.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors['socialLinks.website']"
                    />
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile images"
            description="Your avatar and banner as this brand's community sees them"
        />

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="avatar">Avatar</Label>
                <div class="flex items-center gap-4">
                    <div
                        class="h-16 w-16 shrink-0 overflow-hidden rounded-full border border-border bg-surface-muted"
                        aria-hidden="true"
                    >
                        <img
                            v-if="membershipImages.avatarUrl"
                            :src="membershipImages.avatarUrl"
                            alt=""
                            class="h-full w-full object-cover"
                        />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Input
                            id="avatar"
                            type="file"
                            accept="image/png,image/jpeg,image/gif,image/webp"
                            data-test="avatar-input"
                            @change="submitAvatar"
                        />
                        <InputError :message="avatarForm.errors.file" />
                        <Button
                            v-if="steamAvatarUrl"
                            type="button"
                            variant="secondary"
                            size="sm"
                            data-test="use-steam-avatar-button"
                            @click="copySteamAvatar"
                        >
                            Use my Steam avatar
                        </Button>
                        <InputError
                            :message="(page.props.errors as any)?.steam"
                        />
                        <Form
                            v-if="membershipImages.avatarUrl"
                            v-bind="
                                MembershipImageController.destroyAvatar.form()
                            "
                            v-slot="{ processing: removingAvatar }"
                        >
                            <Button
                                type="submit"
                                variant="secondary"
                                size="sm"
                                :disabled="removingAvatar"
                                data-test="remove-avatar-button"
                            >
                                Remove avatar
                            </Button>
                        </Form>
                    </div>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="banner">Banner</Label>
                <div class="flex flex-col gap-2">
                    <div
                        class="h-24 w-full max-w-sm overflow-hidden rounded-lg border border-border bg-surface-muted"
                        aria-hidden="true"
                    >
                        <img
                            v-if="membershipImages.bannerUrl"
                            :src="membershipImages.bannerUrl"
                            alt=""
                            class="h-full w-full object-cover"
                        />
                    </div>
                    <Input
                        id="banner"
                        type="file"
                        class="max-w-sm"
                        accept="image/png,image/jpeg,image/gif,image/webp"
                        data-test="banner-input"
                        @change="submitBanner"
                    />
                    <InputError :message="bannerForm.errors.file" />
                    <Form
                        v-if="membershipImages.bannerUrl"
                        v-bind="MembershipImageController.destroyBanner.form()"
                        v-slot="{ processing: removingBanner }"
                    >
                        <Button
                            type="submit"
                            variant="secondary"
                            size="sm"
                            class="w-fit"
                            :disabled="removingBanner"
                            data-test="remove-banner-button"
                        >
                            Remove banner
                        </Button>
                    </Form>
                </div>
            </div>
        </div>
    </div>

    <DeleteUser />
</template>
