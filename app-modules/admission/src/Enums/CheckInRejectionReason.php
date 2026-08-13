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
}
