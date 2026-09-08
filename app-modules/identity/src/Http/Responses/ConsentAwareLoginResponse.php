<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Responses;

use Illuminate\Http\Request;
use Konventix\Identity\Concerns\RedirectsOutstandingContractConsent;
use Laravel\Fortify\Http\Responses\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's own login response, with the contract-consent gate in front of
 * it (GitLab #205) — see `RedirectsOutstandingContractConsent`.
 *
 * Extends rather than reimplements: the JSON shape and the `intended()`
 * fallback are Fortify's business, and this class only decides whether the
 * person gets there yet.
 */
final class ConsentAwareLoginResponse extends LoginResponse
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
