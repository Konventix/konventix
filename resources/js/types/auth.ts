/**
 * The shared `auth.user` prop (ADR-0051 §5): ULID, display name, avatar
 * URL, locale — never the whole model. `HandleInertiaRequests` is the only
 * place that builds this shape.
 */
export type AuthenticatedUser = {
    id: string;
    name: string;
    avatarUrl: string | null;
    locale: string;
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
