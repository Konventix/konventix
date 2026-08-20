import { admitsOnDay, isExpired, verifyToken } from './token';
import type { SigningKey } from './token';

/**
 * The door's local decision (ADR-0033). Positive decisions come from the
 * signature; negative ones the signature cannot make come from the synced
 * delta.
 *
 * **The check order deliberately mirrors `Konventix\Admission\Actions\RecordScan`**
 * — signature, expiry, event, day-scope, revocation, re-entry — so a scan
 * decided here offline and the same scan decided on the server later produce
 * the same `reason_code`. If the two ever diverge, an attendee turned away at
 * the door would be recorded as admitted on reconciliation, or vice versa.
 *
 * Reason codes are copied verbatim from
 * `Konventix\Admission\Enums\CheckInRejectionReason`. `evaluate.test.ts` pins
 * them against that enum's values.
 */

export const REJECTION = {
    UnknownAdmission: 'unknown_admission',
    InvalidSignature: 'invalid_signature',
    TokenExpired: 'token_expired',
    NonceSuperseded: 'nonce_superseded',
    AdmissionNotValid: 'admission_not_valid',
    OutsideEventDay: 'outside_event_day',
    NotValidOnThisDay: 'not_valid_on_this_day',
    ReentryNotPermitted: 'reentry_not_permitted',
    WrongEvent: 'wrong_event',
} as const;

export type RejectionReason = (typeof REJECTION)[keyof typeof REJECTION];

export type ReentryPolicy = 'unlimited' | 'once_only' | 'once_per_day';

export type EventDay = {
    day_id: string;
    date: string;
    sequence: number;
    label: string | null;
};

export type LocalState = {
    eventId: string;
    keys: SigningKey[];
    days: EventDay[];
    reentryPolicy: ReentryPolicy;
    /** Admission ULIDs the feed says are revoked. */
    revoked: Set<string>;
    /** Admission ULIDs the feed says are already checked in, plus local accepts. */
    checkedIn: Set<string>;
};

export type Decision =
    | { accepted: true; admissionId: string; dayId: string | null }
    | {
          accepted: false;
          admissionId: string | null;
          dayId: string | null;
          reason: RejectionReason;
      };

/**
 * The event day covering a moment, compared as plain `YYYY-MM-DD` in the
 * event's own local frame. ADR-0027 keeps event time as wall-clock local plus
 * a timezone, so converting through UTC here would reintroduce exactly the
 * drift that ADR avoids — and a scanner's own clock is the only clock a door
 * has when the uplink is down.
 */
export function dayCovering(days: EventDay[], at: Date): EventDay | null {
    const stamp = [
        at.getFullYear(),
        String(at.getMonth() + 1).padStart(2, '0'),
        String(at.getDate()).padStart(2, '0'),
    ].join('-');

    return days.find((day) => day.date === stamp) ?? null;
}

export function admitsAgain(
    policy: ReentryPolicy,
    alreadyCheckedIn: boolean,
): boolean {
    // `once_per_day` is enforced against the *day-scoped* checked-in set the
    // caller supplies, so both bounded policies reduce to the same question
    // here — which keeps this in step with the server, where the day id is
    // part of the lookup rather than part of the policy branch.
    return policy === 'unlimited' ? true : !alreadyCheckedIn;
}

export function evaluateScan(
    token: string,
    state: LocalState,
    at: Date,
): Decision {
    const payload = verifyToken(token, state.keys);

    if (payload === null) {
        return {
            accepted: false,
            admissionId: null,
            dayId: null,
            reason: REJECTION.InvalidSignature,
        };
    }

    if (isExpired(payload, Math.floor(at.getTime() / 1000))) {
        return {
            accepted: false,
            admissionId: null,
            dayId: null,
            reason: REJECTION.TokenExpired,
        };
    }

    if (payload.evt !== state.eventId) {
        return {
            accepted: false,
            admissionId: null,
            dayId: null,
            reason: REJECTION.WrongEvent,
        };
    }

    const day = dayCovering(state.days, at);
    const dayId = day?.day_id ?? null;

    // A calendar that exists but has no day for this moment is a rejection: an
    // admission is valid *on a day* (D-ADM-04). An event with no calendar at
    // all is not day-scoped, so it passes — same rule as the server.
    if (state.days.length > 0 && dayId === null) {
        return {
            accepted: false,
            admissionId: payload.tid,
            dayId: null,
            reason: REJECTION.OutsideEventDay,
        };
    }

    if (!admitsOnDay(payload, dayId)) {
        return {
            accepted: false,
            admissionId: payload.tid,
            dayId,
            reason: REJECTION.NotValidOnThisDay,
        };
    }

    // The delta's negative facts. A stale feed means these sets are merely
    // older, never absent — the door degrades to signature-only, it never
    // closes (ADR-0033).
    if (state.revoked.has(payload.tid)) {
        return {
            accepted: false,
            admissionId: payload.tid,
            dayId,
            reason: REJECTION.AdmissionNotValid,
        };
    }

    const key =
        state.reentryPolicy === 'once_per_day' && dayId !== null
            ? `${payload.tid}:${dayId}`
            : payload.tid;

    if (!admitsAgain(state.reentryPolicy, state.checkedIn.has(key))) {
        return {
            accepted: false,
            admissionId: payload.tid,
            dayId,
            reason: REJECTION.ReentryNotPermitted,
        };
    }

    return { accepted: true, admissionId: payload.tid, dayId };
}

/**
 * The key under which an accepted entry is remembered locally, matching the
 * lookup `evaluateScan` performs.
 */
export function checkedInKey(
    admissionId: string,
    policy: ReentryPolicy,
    dayId: string | null,
): string {
    return policy === 'once_per_day' && dayId !== null
        ? `${admissionId}:${dayId}`
        : admissionId;
}
