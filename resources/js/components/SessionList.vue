<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import sessionRoutes from '@/routes/sessions';

/**
 * One row per session the server still honours: the device line the
 * backend derived from the user agent (the raw header rides along as the
 * tooltip), the IP address named as an address — nothing resolves it to a
 * place, so the list promises no "location" — and the last activity.
 */
defineProps<{
    sessions: Konventix.Identity.Data.SessionData[];
}>();
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    "Sessions on this brand. Signing in from another brand's domain creates a separate session there, so this list does not necessarily cover every brand you have an account with.",
                )
            }}
        </p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="session in sessions"
                :key="session.id"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2">
                        <span
                            class="text-sm font-medium"
                            :title="session.userAgent ?? undefined"
                        >
                            {{ session.device }}
                        </span>
                        <Badge
                            v-if="session.isCurrentDevice"
                            variant="secondary"
                        >
                            {{ $t('This device') }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="session.ipAddress">
                            {{
                                $t('IP address :address', {
                                    address: session.ipAddress,
                                })
                            }}
                        </span>
                        <span v-else>{{ $t('IP address unknown') }}</span>
                        ·
                        {{
                            $t('Last active :time', {
                                time: formatDateTime(session.lastActiveAt),
                            })
                        }}
                    </p>
                </div>

                <Form
                    v-if="!session.isCurrentDevice"
                    v-bind="sessionRoutes.destroy.form(session.id)"
                    :options="{ preserveScroll: true }"
                >
                    <Button type="submit" variant="outline" size="sm">
                        {{ $t('Revoke') }}
                    </Button>
                </Form>
            </li>
        </ul>

        <Form
            v-bind="sessionRoutes.destroyOthers.form()"
            :options="{ preserveScroll: true }"
        >
            <Button
                type="submit"
                variant="secondary"
                data-test="revoke-other-sessions-button"
            >
                {{ $t('Log out other sessions') }}
            </Button>
        </Form>
    </div>
</template>
