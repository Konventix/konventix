import type {
    AuthenticationResponseJSON,
    CeremonyFailure,
    ExtensionResultsJSON,
    RegistrationResponseJSON,
} from './webauthnCodec';
import {
    classifyCeremonyError,
    serializeAssertion,
    serializeAttestation,
    toCreationOptions,
    toRequestOptions,
} from './webauthnCodec';

/**
 * The browser half of the passkey client (bugfix round 2026-09-21, Task 16):
 * fetch the options Fortify's passkey routes hand out, run the WebAuthn
 * ceremony, and give back the JSON the matching POST route validates. No
 * npm dependency (stated assumption of the task); the pure translation
 * lives in `webauthnCodec.ts`, where vitest can reach it.
 *
 * Only the two *options* endpoints are fetched here. The credential itself
 * is posted through Inertia by the caller — a plain form-shaped visit — so
 * the server's redirects (the consent gate on login, the password
 * confirmation on add, `back()` with the new list) and its validation
 * errors (`errors.credential`) arrive the way every other form's do.
 *
 * The relying party is the host of the page: the server reads it off the
 * request (`BindPasskeyRelyingParty`), and the browser only offers a passkey
 * on the domain it was created for.
 */
export type CeremonyResult<T> =
    { ok: true; credential: T } | { ok: false; failure: CeremonyFailure };

export function isWebAuthnSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'PublicKeyCredential' in window &&
        typeof navigator !== 'undefined' &&
        navigator.credentials !== undefined
    );
}

type OptionsFetch<T> =
    { ok: true; options: T } | { ok: false; failure: CeremonyFailure };

/**
 * `GET` the options as JSON. A 423 is Laravel's `password.confirm`
 * middleware saying the session has not confirmed the password recently
 * enough; the caller turns that into the confirmation round trip.
 */
async function fetchOptions<T>(url: string): Promise<OptionsFetch<T>> {
    let response: Response;

    try {
        response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
    } catch {
        return { ok: false, failure: 'failed' };
    }

    if (response.status === 423) {
        return { ok: false, failure: 'password-confirmation-required' };
    }

    if (!response.ok) {
        return { ok: false, failure: 'failed' };
    }

    const payload = (await response.json()) as { options?: T };

    if (payload.options === undefined) {
        return { ok: false, failure: 'failed' };
    }

    return { ok: true, options: payload.options };
}

/** Registration: options from the server, a new credential from the device. */
export async function createPasskey(
    optionsUrl: string,
): Promise<CeremonyResult<RegistrationResponseJSON>> {
    if (!isWebAuthnSupported()) {
        return { ok: false, failure: 'unsupported' };
    }

    const fetched =
        await fetchOptions<PublicKeyCredentialCreationOptionsJSON>(optionsUrl);

    if (!fetched.ok) {
        return fetched;
    }

    try {
        const credential = await navigator.credentials.create({
            publicKey: toCreationOptions(fetched.options),
        });

        if (!(credential instanceof PublicKeyCredential)) {
            return { ok: false, failure: 'failed' };
        }

        return {
            ok: true,
            credential: serializeAttestation({
                id: credential.id,
                rawId: credential.rawId,
                type: credential.type,
                authenticatorAttachment: credential.authenticatorAttachment,
                response:
                    credential.response as AuthenticatorAttestationResponse,
                // lib.dom types the result as a closed interface; on the
                // wire it is plain JSON (`ExtensionResultsJSON`), which is
                // what Inertia can post as form data.
                getClientExtensionResults: () =>
                    credential.getClientExtensionResults() as ExtensionResultsJSON,
            }),
        };
    } catch (error) {
        return { ok: false, failure: classifyCeremonyError(error) };
    }
}

/** Login: options from the server, an assertion from whichever passkey the person picks. */
export async function requestPasskeyAssertion(
    optionsUrl: string,
): Promise<CeremonyResult<AuthenticationResponseJSON>> {
    if (!isWebAuthnSupported()) {
        return { ok: false, failure: 'unsupported' };
    }

    const fetched =
        await fetchOptions<PublicKeyCredentialRequestOptionsJSON>(optionsUrl);

    if (!fetched.ok) {
        return fetched;
    }

    try {
        const credential = await navigator.credentials.get({
            publicKey: toRequestOptions(fetched.options),
        });

        if (!(credential instanceof PublicKeyCredential)) {
            return { ok: false, failure: 'failed' };
        }

        return {
            ok: true,
            credential: serializeAssertion({
                id: credential.id,
                rawId: credential.rawId,
                type: credential.type,
                authenticatorAttachment: credential.authenticatorAttachment,
                response: credential.response as AuthenticatorAssertionResponse,
                getClientExtensionResults: () =>
                    credential.getClientExtensionResults() as ExtensionResultsJSON,
            }),
        };
    } catch (error) {
        return { ok: false, failure: classifyCeremonyError(error) };
    }
}
