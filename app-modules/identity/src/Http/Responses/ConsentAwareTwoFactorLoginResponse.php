<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Responses;

use Illuminate\Http\Request;
use Konventix\Identity\Concerns\RedirectsOutstandingContractConsent;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same gate on the other way in (GitLab #205): a two-factor challenge
 * ends in its own response class, and a login that only interrupted the
 * password path would let every account with 2FA past the contract text.
 */
final class ConsentAwareTwoFactorLoginResponse extends TwoFactorLoginResponse
{
    use RedirectsOutstandingContractConsent;

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $this->outstandingContractRedirect($request) ?? parent::toResponse($request);
    }
}
