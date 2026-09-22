import type { ToasterProps } from 'vue-sonner';

/**
 * Toasts and the floating feedback button share the bottom-right corner
 * (#319). One rule keeps them apart: **while the shell mounts the feedback
 * button, the toaster's bottom edge sits above it** — on phones and on the
 * desktop alike. vue-sonner stacks every further toast upwards from that
 * edge, collapsed or expanded, so a stack never reaches back down into the
 * button either.
 *
 * The button's geometry is Support's `SupportLauncher.vue`: `bottom-4`
 * (16 px from the viewport's bottom) and `min-h-11` (44 px tall), the same
 * at every width. `toasterOffset.test.ts` reads those two classes out of
 * the component, so moving the button without moving this fails the build
 * rather than the phone.
 *
 * Only `bottom` is set: the toaster's other sides keep vue-sonner's own
 * defaults (24 px on the desktop, 16 px and full width on a phone).
 */
export const FEEDBACK_BUTTON_BOTTOM_PX = 16;
export const FEEDBACK_BUTTON_HEIGHT_PX = 44;
/** The air between the button's top edge and the lowest toast. */
export const FEEDBACK_BUTTON_GAP_PX = 8;

export const FEEDBACK_BUTTON_CLEARANCE_PX =
    FEEDBACK_BUTTON_BOTTOM_PX +
    FEEDBACK_BUTTON_HEIGHT_PX +
    FEEDBACK_BUTTON_GAP_PX;

/**
 * The `offset`/`mobileOffset` props for the shell's `<Toaster>`: the
 * clearance above the button when it is mounted, nothing (vue-sonner's
 * defaults) when the brand or console carries no feedback button.
 */
export function toasterOffsets(
    feedbackButtonShown: boolean,
): Pick<ToasterProps, 'offset' | 'mobileOffset'> {
    if (!feedbackButtonShown) {
        return {};
    }

    return {
        offset: { bottom: FEEDBACK_BUTTON_CLEARANCE_PX },
        mobileOffset: { bottom: FEEDBACK_BUTTON_CLEARANCE_PX },
    };
}
