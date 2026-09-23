import { transChoice } from 'laravel-vue-i18n';

/**
 * What `/my/registrations`' live region says after the cancelled-registrations
 * link is used (#239, branch review findings 21 and NEW-3).
 *
 * A plain function beside the page rather than a `computed` inside it, for
 * the reason `versionForm.ts` and `consentLabel.ts` are: there is no Vue
 * component runner here (`vitest.config.ts`), and the thing worth pinning is
 * that the two toggle states produce *different* sentences — a live region
 * whose text does not change announces nothing, however correctly it is
 * marked up. The other half of NEW-3 is `preserve-state` on the link, which
 * keeps the page mounted so this text is an update rather than a remount.
 */
export function registrationListSummary(
    shown: number,
    cancelledCount: number,
    showAll: boolean,
): string {
    // `[0,*]` after `{1}`: zero reads as a plural ("0 registrations").
    if (showAll) {
        return transChoice(
            '{1} Showing all :count registration, cancelled ones included.|[0,*] Showing all :count registrations, cancelled ones included.',
            shown,
        );
    }

    return transChoice(
        '{1} Showing :count registration, :cancelled cancelled hidden.|[0,*] Showing :count registrations, :cancelled cancelled hidden.',
        shown,
        { cancelled: String(cancelledCount) },
    );
}
