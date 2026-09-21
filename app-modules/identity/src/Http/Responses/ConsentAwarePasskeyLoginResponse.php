<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Responses;

use Illuminate\Http\Request;
use Konventix\Identity\Concerns\RedirectsOutstandingContractConsent;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The package's passkey login response with the contract-consent gate in
 * front of it — the third login path (after the password and the two-factor
 * challenge) that ends in a response object rather than on a route, and so
 * the third place `RedirectsOutstandingContractConsent` has to sit.
 *
 * Same split as `ConsentAwareLoginResponse`: the JSON shape and the
 * `intended()` fallback are the package's, this class only decides whether
 * the person gets there yet. The login page posts the assertion through
 * Inertia, not as JSON, precisely so this gate runs.
 */
final class ConsentAwarePasskeyLoginResponse extends PasskeyLoginResponse
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
