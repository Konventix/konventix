import { useHttp } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref, watch } from 'vue';

type KnownIssue = Konventix.Support.Data.KnownIssueData;

/**
 * The dialog's known-issue banner (design §9.4).
 *
 * **Fetched when the dialog opens, and never on page load.** A shared
 * Inertia prop would mean a query on every page of every brand host to
 * render a banner almost nobody sees; the design keeps the shared props
 * lean and pays the cost only for the people who actually open the dialog.
 *
 * Once per open, not once per session: an operator marking an issue while
 * somebody has the page open is exactly the case the banner is for, and
 * `GET /support/known-issues` is a sixty-second cache read on the server.
 *
 * A failed fetch resolves to nothing at all. This is decoration on the way
 * to a report — a visitor whose checkout is broken must never be shown an
 * error about the thing that was going to tell them about errors, and they
 * may still file the report either way.
 *
 * `endpoint` is null on the operator console, which registers no brand-host
 * route and resolves no brand to have known issues for (ADR-0029/ADR-0063);
 * the banner then never appears and nothing is requested.
 */
export function useKnownIssues(
    open: () => boolean,
    endpoint: () => string | null,
): { knownIssues: Ref<KnownIssue[]> } {
    const knownIssues = ref<KnownIssue[]>([]);
    const http = useHttp<
        Record<string, never>,
        { knownIssues: KnownIssue[] }
    >();

    watch(open, (isOpen) => {
        const url = endpoint();

        if (!isOpen || url === null) {
            knownIssues.value = [];

            return;
        }

        http.get(url, {
            onSuccess: (response) => {
                knownIssues.value = response.knownIssues ?? [];
            },
        }).catch(() => {
            knownIssues.value = [];
        });
    });

    return { knownIssues };
}
