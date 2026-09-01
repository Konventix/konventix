<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import ProfileController from '@/actions/Konventix/Identity/Http/Controllers/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

const { profile } = defineProps<{
    profile: {
        name: string;
        email: string;
        username: string;
        tagline: string | null;
        statusEmoji: string | null;
        socialLinks: Record<string, string>;
    };
}>();
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
                    <InputError class="mt-2" :message="errors['socialLinks.steam']" />
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
                    <InputError class="mt-2" :message="errors['socialLinks.discord']" />
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
                    <InputError class="mt-2" :message="errors['socialLinks.twitch']" />
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
                    <InputError class="mt-2" :message="errors['socialLinks.youtube']" />
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
                    <InputError class="mt-2" :message="errors['socialLinks.x']" />
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
                    <InputError class="mt-2" :message="errors['socialLinks.instagram']" />
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
                    <InputError class="mt-2" :message="errors['socialLinks.website']" />
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
