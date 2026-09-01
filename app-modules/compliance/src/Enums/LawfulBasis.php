<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * Why a policy may process someone's data (Art. 6(1) GDPR), and therefore
 * whether leaving it is a right or a request (ADR-0050 §7).
 *
 * Art. 7(3) makes withdrawing consent as easy as giving it, so a
 * consent-based policy is always self-service and no setting may say
 * otherwise. Modelling the basis rather than the withdrawal mechanic is
 * what makes that configuration unrepresentable rather than merely
 * discouraged.
 */
enum LawfulBasis: string
{
    case Consent = 'consent';
    case Contract = 'contract';
    case LegitimateInterest = 'legitimate_interest';

    public function allowsSelfServiceWithdrawal(): bool
    {
        return $this === self::Consent;
    }
}
