<?php

declare(strict_types=1);

namespace Konventix\Admission\Tokens;

/**
 * The decoded body of a KVX1 admission token — `{tid, nonce, iat, exp, evt,
 * days}`, generalized from LanCore's ticket-scoped `LCT1` body to an
 * admission-scoped one (`docs/inventory/commerce-registration-admission.md`).
 *
 * `days` is the field ADR-0033 forced: D-ADM-04 makes an event-day
 * first-class, so a token has to say which days it admits on, and the device
 * has to be able to check that against its local calendar without asking the
 * server. An empty list means "every day of the event".
 *
 * `nonce` exists so an admission's token can be invalidated without revoking
 * the admission — reissuing with a fresh nonce strands the old QR.
 *
 * Deliberately carries **no holder name or email**. The QR is scanned by
 * devices that ADR-0033 requires to hold zero PII; putting a name in the
 * token would smuggle attendee data onto venue hardware through the one
 * channel the ADR leaves open.
 */
final readonly class AdmissionTokenPayload
{
    /**
     * @param  list<string>  $dayIds  event-day ULIDs; empty means all days
     */
    public function __construct(
        public string $admissionId,
        public string $eventId,
        public string $nonce,
        public int $issuedAt,
        public int $expiresAt,
        public array $dayIds = [],
    ) {}

    /**
     * @return array{tid: string, evt: string, nonce: string, iat: int, exp: int, days: list<string>}
     */
    public function toArray(): array
    {
        return [
            'tid' => $this->admissionId,
            'evt' => $this->eventId,
            'nonce' => $this->nonce,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
            'days' => $this->dayIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function ofDecodedBody(array $body): self
    {
        return new self(
            admissionId: (string) ($body['tid'] ?? ''),
            eventId: (string) ($body['evt'] ?? ''),
            nonce: (string) ($body['nonce'] ?? ''),
            issuedAt: (int) ($body['iat'] ?? 0),
            expiresAt: (int) ($body['exp'] ?? 0),
            dayIds: array_values(array_map(
                strval(...),
                is_array($body['days'] ?? null) ? $body['days'] : [],
            )),
        );
    }

    /**
     * Whether this token admits on the given event day. An empty `days` list
     * is the whole-event case.
     */
    public function admitsOnDay(?string $dayId): bool
    {
        if ($this->dayIds === []) {
            return true;
        }

        return $dayId !== null && in_array($dayId, $this->dayIds, true);
    }
}
