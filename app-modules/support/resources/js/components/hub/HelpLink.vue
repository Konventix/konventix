<script setup lang="ts">
import { useSupportDialog } from '../useSupportDialog';

/**
 * "Hilfe" on the live hub (D-HUB-05, design §5.2). A `HubLink` with a
 * `component` instead of a `url`, and the only one of the four launch
 * contributions that needs one: the report dialog is already mounted on
 * the page, so this opens it rather than navigating the participant away
 * from the event they are standing at.
 *
 * `label` is the only prop — the shell passes a link's label and nothing
 * else (`HubLinkData`), which is the whole point of a component link: what
 * it *does* is this module's business.
 *
 * `useSupportDialog` is this module's own module-level state, the same one
 * the topbar launcher opens, so the two can never disagree about whether
 * the dialog is open.
 */
defineProps<{ label: string }>();

const { open } = useSupportDialog();
</script>

<template>
    <button
        type="button"
        class="text-sm text-accent underline-offset-4 hover:underline"
        @click="open()"
    >
        {{ label }}
    </button>
</template>
