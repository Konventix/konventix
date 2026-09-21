/**
 * The report dialog's rules, as pure functions (design §6.2).
 *
 * The client-side half of validation exists to save somebody a round trip,
 * never to decide anything: `SupportRequestInputData::rules()` is the
 * authority and the endpoint re-checks every one of these. So the bounds
 * below are copies on purpose, and they are copies of a *spec* number
 * (10–4000 characters, at least one category) rather than of an
 * implementation detail — if they ever disagree with the server, the person
 * sees the server's 422 with the server's message, which is the correct
 * failure mode.
 *
 * Kept out of the `.vue` file so the rules are testable in the node
 * environment vitest runs in, with no DOM and no Inertia.
 */

/** Design §4.1: "kaputt" is not a report anybody can act on. */
export const MESSAGE_MIN = 10;

/** And a pasted stack trace is what the correlation id is for. */
export const MESSAGE_MAX = 4000;

export interface SupportCategoryOption {
    value: string;
    label: string;
}

/**
 * The five of #88, in the order the dialog offers them — the same order and
 * the same labels as `Konventix\Support\Enums\SupportCategory`, because the
 * value posted here is the value stored, labelled in the console and used
 * as a metric label.
 *
 * `purchase_affected` is first because it is the one with an operational
 * meaning: it raises an alert on the on-call channel (D-SUP-06).
 */
export const SUPPORT_CATEGORIES: SupportCategoryOption[] = [
    { value: 'purchase_affected', label: 'Purchase affected' },
    { value: 'visibility', label: 'Something is not visible' },
    { value: 'function', label: 'Something does not work' },
    { value: 'display', label: 'Something looks wrong' },
    { value: 'suggestion', label: 'Suggestion' },
];

/** Multi-select: clicking a chip that is on turns it off. */
export function toggleCategory(selected: string[], value: string): string[] {
    return selected.includes(value)
        ? selected.filter((entry) => entry !== value)
        : [...selected, value];
}

/**
 * The counter under the textarea. It counts *up to* the maximum rather than
 * down from it, and says so, because "3990 left" tells a person nothing
 * about whether they have written enough yet — and the minimum is the bound
 * they are far more likely to hit.
 */
export function counterText(length: number): string {
    if (length < MESSAGE_MIN) {
        return `${length} of ${MESSAGE_MIN} characters minimum`;
    }

    return `${length} of ${MESSAGE_MAX} characters`;
}

export interface SupportFormValues {
    categories: string[];
    message: string;
    email: string;
    contactConsent: boolean;
}

/**
 * What the dialog can say before asking the server. Keys are the field
 * names the endpoint answers 422 with, so the same `InputError` slots
 * render both.
 */
export function localErrors(
    values: SupportFormValues,
    requiresEmail: boolean,
): Record<string, string> {
    const errors: Record<string, string> = {};

    if (values.categories.length === 0) {
        errors.categories = 'Pick at least one thing this is about.';
    }

    const length = values.message.trim().length;

    if (length < MESSAGE_MIN) {
        errors.message = `Please write at least ${MESSAGE_MIN} characters so we can act on it.`;
    } else if (values.message.length > MESSAGE_MAX) {
        errors.message = `Please keep it under ${MESSAGE_MAX} characters.`;
    }

    if (requiresEmail && values.email.trim() === '') {
        errors.email = 'Tell us an e-mail address so we can answer you.';
    }

    return errors;
}

/**
 * The toast after a successful submission (design §6.2). The reference is
 * the same eight characters the receipt mail and the console print, so the
 * person and the operator can say the same word out loud.
 */
export function successMessage(
    reference: string,
    isGuest: boolean,
    email: string,
): string {
    const thanks = `Thanks — your reference is ${reference}.`;

    if (isGuest) {
        const address = email.trim();

        return address === ''
            ? `${thanks} We sent you a confirmation.`
            : `${thanks} We sent a confirmation to ${address}.`;
    }

    return `${thanks} You can follow it under My support requests.`;
}

/**
 * What a failed submission says when the failure was not a field error.
 * 429 is the one worth its own sentence: it is the limiter doing its job
 * (§6.4), and the person is not being refused, only asked to wait.
 */
export function transportErrorMessage(status: number | null): string {
    if (status === 429) {
        return 'Too many reports, please try again in a few minutes.';
    }

    if (status === null) {
        return 'Your report did not reach us. Please check your connection and try again.';
    }

    return 'Something went wrong on our side. Please try again.';
}
