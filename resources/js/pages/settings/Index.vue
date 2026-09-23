<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Card, CardContent } from '@/components/ui/card';
import AppTopbarLayout from '@/layouts/AppTopbarLayout.vue';
import { settingsSections } from '@/lib/settingsSections';

/**
 * The account hub the topbar's "Settings" entry points at. It exists so that
 * everything an account can be configured to do is visible in one place —
 * the previous behaviour, a redirect straight into the profile form, hid
 * that anything else lived here.
 *
 * Deliberately not wrapped in the settings sub-navigation layout: repeating
 * the same six entries in a sidebar next to the same six cards would be
 * saying it twice.
 */
// The shell only. Its name puts it under the `settings/` default, which
// also wraps pages in the settings sub-navigation — repeating the same six
// entries beside the same six cards would be saying it twice.
defineOptions({ layout: AppTopbarLayout });
</script>

<template>
    <Head :title="$t('Settings')" />

    <!-- Same container as the settings layout, so the hub and its sections
         line up at the same width and the same offset. -->
    <div class="mx-auto w-full max-w-5xl px-4 py-6">
        <!--
            The same heading the settings layout puts above every section, so
            the hub and the pages it links to are visibly one place. `as="h1"`
            because here it *is* the page title — the sections render their
            own.
        -->
        <Heading
            as="h1"
            :title="$t('Settings')"
            :description="$t('Manage your profile and account settings')"
        />

        <ul class="grid gap-3 sm:grid-cols-2">
            <li v-for="section in settingsSections" :key="section.title">
                <Card class="h-full transition hover:border-accent">
                    <CardContent class="p-0">
                        <Link
                            :href="section.href"
                            class="flex h-full items-start gap-3 p-4"
                        >
                            <component
                                :is="section.icon"
                                class="mt-0.5 size-5 shrink-0 text-text-muted"
                            />
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="font-medium text-text">
                                    {{ section.title }}
                                </span>
                                <span class="text-sm text-text-muted">
                                    {{ section.description }}
                                </span>
                            </span>
                            <ChevronRight
                                class="mt-0.5 size-4 shrink-0 text-text-muted"
                            />
                        </Link>
                    </CardContent>
                </Card>
            </li>
        </ul>
    </div>
</template>
