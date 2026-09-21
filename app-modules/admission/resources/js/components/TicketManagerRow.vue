<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Armchair, Check, Copy, Ticket as TicketIcon, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import HolderController from '@/actions/Konventix/Admission/Http/Controllers/HolderController';
import InviteController from '@/actions/Konventix/Admission/Http/Controllers/InviteController';
import TicketPageController from '@/actions/Konventix/Admission/Http/Controllers/TicketPageController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import InviteForm from './InviteForm.vue';

/**
 * One admission in the buyer's ticket manager (#292 §4.5): who holds it,
 * its seat, and the actions its state allows. The rules, in the order the
 * template asks them:
 *
 * - unassigned, no live invite → *This one is mine*, *Invite*;
 * - unassigned, live invite → *Copy link* (or the address it went to),
 *   *Withdraw*;
 * - assigned, not checked in → *Take back*;
 * - checked in → nothing to change, and the row says so.
 *
 * *Ticket* is offered on every row: the manager may open any ticket of
 * their registration. "This one is mine" is a primary action only while
 * the manager holds none of this registration's tickets; once they do, a
 * second one for themselves is still allowed but no longer suggested.
 *
 * `navigator.clipboard` is unavailable over plain HTTP and in older
 * browsers, so the link stays in a readable, selectable input and copying
 * is only the convenience on top of it (`StripeAccountCard.vue`'s stance).
 *
 * The holder is a handle (ADR-0065): `@username`, or "Assigned" for a
 * person the projection has no handle for yet — never the display name.
 */
type Row = Konventix.Admission.Data.ManagedAdmissionData;

const props = defineProps<{
    admission: Row;
    managerHoldsOne: boolean;
    error?: string;
}>();

const inviting = ref(false);
const copied = ref(false);
const busy = ref(false);

/**
 * Where the invitation went and how long it stands. `expiresAt` arrives
 * already formatted in the brand's zone, the way the mail printed it.
 */
const inviteNote = computed(() => {
    const invite = props.admission.invite;

    if (!invite) {
        return '';
    }

    // datetime-raw: invite.expiresAt — formatted on the server in the brand's zone
    return invite.intendedEmail
        ? `Invitation sent to ${invite.intendedEmail}, valid until ${invite.expiresAt}.`
        : `Invite link created, valid until ${invite.expiresAt}.`;
});

const holderLabel = computed(() => {
    if (props.admission.isSelf) {
        return 'You';
    }

    if (props.admission.holderUsername) {
        return `@${props.admission.holderUsername}`;
    }

    return props.admission.holderUserId ? 'Assigned' : 'Not yet assigned';
});

function run(request: () => void): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    request();
}

const visitOptions = {
    preserveScroll: true,
    onFinish: () => {
        busy.value = false;
    },
};

function claimForMyself(): void {
    run(() =>
        router.post(
            HolderController.store.url({ admission: props.admission.id }),
            {},
            visitOptions,
        ),
    );
}

function takeBack(): void {
    run(() =>
        router.delete(
            HolderController.destroy.url({ admission: props.admission.id }),
            visitOptions,
        ),
    );
}

function withdrawInvite(): void {
    run(() =>
        router.delete(
            InviteController.destroy.url({ admission: props.admission.id }),
            visitOptions,
        ),
    );
}

async function copyInviteLink(): Promise<void> {
    if (!props.admission.invite) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.admission.invite.url);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
    >
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-text">{{ holderLabel }}</span>
                    <span
                        class="rounded-full bg-subtle px-2 py-0.5 text-xs font-medium text-text capitalize"
                    >
                        {{ props.admission.role }}
                    </span>
                    <span
                        v-if="props.admission.checkedIn"
                        class="rounded-full bg-success/15 px-2 py-0.5 text-xs font-medium text-success"
                    >
                        Checked in
                    </span>
                </span>
                <span
                    v-if="props.admission.seat"
                    class="flex items-center gap-1 text-sm text-text-muted"
                >
                    <Armchair class="size-4" aria-hidden="true" />
                    Seat {{ props.admission.seat.placeLabel }}
                </span>
                <span
                    v-else-if="props.admission.invite"
                    class="text-sm text-text-muted"
                >
                    {{ inviteNote }}
                </span>
            </span>

            <span class="flex flex-wrap items-center gap-2">
                <Link
                    :href="
                        TicketPageController.show.url({
                            admission: props.admission.id,
                        })
                    "
                    class="inline-flex min-h-9 items-center gap-1 rounded-md border border-border px-3 text-sm font-medium text-text hover:border-accent"
                >
                    <TicketIcon class="size-4" aria-hidden="true" />
                    Ticket
                </Link>

                <template
                    v-if="
                        !props.admission.holderUserId && !props.admission.invite
                    "
                >
                    <Button
                        type="button"
                        size="sm"
                        :variant="props.managerHoldsOne ? 'outline' : 'default'"
                        :disabled="busy"
                        @click="claimForMyself"
                    >
                        This one is mine
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :aria-expanded="inviting"
                        @click="inviting = !inviting"
                    >
                        Invite
                    </Button>
                </template>

                <template
                    v-else-if="
                        !props.admission.holderUserId && props.admission.invite
                    "
                >
                    <Button
                        v-if="!props.admission.invite.intendedEmail"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="copyInviteLink"
                    >
                        <Check
                            v-if="copied"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <Copy v-else class="size-4" aria-hidden="true" />
                        {{ copied ? 'Copied' : 'Copy link' }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="busy"
                        @click="withdrawInvite"
                    >
                        <X class="size-4" aria-hidden="true" />
                        Withdraw
                    </Button>
                </template>

                <Button
                    v-else-if="!props.admission.checkedIn"
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="busy"
                    @click="takeBack"
                >
                    Take back
                </Button>
            </span>
        </div>

        <!-- The bearer link, readable and selectable whether or not the
             clipboard is available. -->
        <label
            v-if="
                props.admission.invite &&
                !props.admission.invite.intendedEmail &&
                !props.admission.holderUserId
            "
            class="flex flex-col gap-1 text-xs text-text-muted"
        >
            Invite link — anyone who opens it first can take this ticket
            <input
                type="text"
                readonly
                :value="props.admission.invite.url"
                class="w-full rounded-md border border-border bg-surface px-2 py-1 font-mono text-xs text-text"
                @focus="($event.target as HTMLInputElement).select()"
            />
        </label>

        <InviteForm
            v-if="
                inviting &&
                !props.admission.holderUserId &&
                !props.admission.invite
            "
            :admission-id="props.admission.id"
            @done="inviting = false"
        />

        <InputError :message="props.error" />
    </li>
</template>
