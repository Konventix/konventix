/**
 * The shared `auth.user` prop (ADR-0051 §5): ULID, display name, avatar URL,
 * locale, timezone — never the whole model. `HandleInertiaRequests` is the
 * only place that builds this shape.
 *
 * `timezone` is `users.timezone` (#243), and `null` is a value rather than a
 * gap: it means the person has chosen no zone of their own and every
 * timestamp is shown on the clock of whatever device they are reading on.
 * `resources/js/lib/datetime.ts` is the only consumer.
 */
export type AuthenticatedUser = {
    id: string;
    name: string;
    avatarUrl: string | null;
    locale: string;
    timezone: string | null;
};

export type Auth = {
    user: AuthenticatedUser | null;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */
