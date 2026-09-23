<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * Why a scan was refused. Coded rather than free text so the reasons are
 * countable across devices and translatable at the door — and so the audit
 * trail stays comparable between an offline client and the server.
 *
 * Note what is *not* here: nothing about payment. The door asks "is this
 * admission valid?", never "is it paid?" (ADR-0005), and
 * `EvaluateAdmissionValidityTest` enforces that by asserting the validity
 * Action's source never mentions payment at all.
 */
enum CheckInRejectionReason: string
{
    case UnknownAdmission = 'unknown_admission';
    case InvalidSignature = 'invalid_signature';
    case TokenExpired = 'token_expired';
    case NonceSuperseded = 'nonce_superseded';
    case AdmissionNotValid = 'admission_not_valid';
    case OutsideEventDay = 'outside_event_day';
    case NotValidOnThisDay = 'not_valid_on_this_day';
    case ReentryNotPermitted = 'reentry_not_permitted';
    case WrongEvent = 'wrong_event';

    /**
     * What the operator console says when the shared core refused a check-in
     * (attendee-list design §4.3: every refusal is a sentence, never a 500).
     * English, as all console copy is (ADR-0001). The token-level reasons
     * cannot come from the console, which never handles a token, but every
     * case has a sentence so a caller never has to guess.
     */
    public function consoleSentence(): string
    {
        return match ($this) {
            self::UnknownAdmission => 'This ticket does not exist.',
            self::InvalidSignature => 'The ticket code could not be verified.',
            self::TokenExpired => 'The ticket code has expired.',
            self::NonceSuperseded => 'This ticket code has been replaced by a newer one.',
            self::AdmissionNotValid => 'This ticket is not valid: it has been revoked or is outside its validity period.',
            self::OutsideEventDay => 'There is no event day today, so nobody can be checked in right now.',
            self::NotValidOnThisDay => 'This ticket is not valid on this event day.',
            self::ReentryNotPermitted => 'This event does not permit re-entry, and this ticket has already been used to enter.',
            self::WrongEvent => 'This ticket belongs to a different event.',
        };
    }
}
