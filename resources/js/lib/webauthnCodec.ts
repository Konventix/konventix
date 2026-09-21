/**
 * The pure half of the passkey client (bugfix round 2026-09-21, Task 16):
 * base64url and the translation between the JSON `laravel/passkeys` speaks
 * and the `ArrayBuffer`s `navigator.credentials` insists on. Written by
 * hand rather than pulled from npm (stated assumption of the task) and kept
 * free of `fetch`, `navigator` and Vue so vitest can pin it in the `node`
 * environment (`webauthnCodec.test.ts`); the browser calls live in
 * `webauthn.ts`.
 *
 * The wire shapes are WebAuthn Level 3's own JSON forms
 * (`PublicKeyCredentialCreationOptionsJSON`, `RegistrationResponseJSON`, …,
 * from lib.dom), not backend Data classes, which is why they are not in
 * `types/generated.d.ts`. Newer browsers ship `parseCreationOptionsFromJSON()`
 * and `toJSON()` for exactly this; the hand-written versions keep one code
 * path for the browsers that do not.
 */

/**
 * What `getClientExtensionResults()` yields once it is on the wire. Not
 * lib.dom's `AuthenticationExtensionsClientOutputs`: that is a closed
 * interface with no index signature, and Inertia's `FormDataConvertible`
 * only accepts objects that have one. The body is posted as form data, so
 * the JSON shape is the honest type here.
 */
export type ExtensionResultsJSON = {
    [key: string]:
        | string
        | number
        | boolean
        | null
        | ExtensionResultsJSON
        | Array<string | number | boolean | null | ExtensionResultsJSON>;
};

/**
 * WebAuthn Level 3's `RegistrationResponseJSON` and
 * `AuthenticationResponseJSON` — the `toJSON()` forms of a credential.
 * lib.dom (TypeScript 5.9) ships the *options* JSON types but not these
 * two yet, so they are spelled out here with the spec's names and fields.
 */
export type RegistrationResponseJSON = {
    id: string;
    rawId: string;
    type: 'public-key';
    authenticatorAttachment?: string;
    response: {
        clientDataJSON: string;
        attestationObject: string;
        transports: string[];
    };
    clientExtensionResults: ExtensionResultsJSON;
};

export type AuthenticationResponseJSON = {
    id: string;
    rawId: string;
    type: 'public-key';
    authenticatorAttachment?: string;
    response: {
        clientDataJSON: string;
        authenticatorData: string;
        signature: string;
        userHandle?: string;
    };
    clientExtensionResults: ExtensionResultsJSON;
};

/** Base64url without padding, the encoding WebAuthn JSON uses for bytes. */
export function base64UrlEncode(bytes: ArrayBuffer | Uint8Array): string {
    const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    let binary = '';

    for (const byte of view) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

/**
 * Tolerant of the standard alphabet and of padding, so a value that took a
 * detour through a less careful encoder still decodes.
 */
export function base64UrlDecode(text: string): Uint8Array {
    const base64 = text.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

/**
 * A decoded byte string as the `BufferSource` the WebAuthn API expects —
 * a fresh `ArrayBuffer`, never a view onto a larger one.
 */
function toBuffer(text: string): ArrayBuffer {
    return base64UrlDecode(text).slice().buffer as ArrayBuffer;
}

function toDescriptors(
    descriptors: readonly PublicKeyCredentialDescriptorJSON[] | undefined,
): PublicKeyCredentialDescriptor[] | undefined {
    if (descriptors === undefined) {
        return undefined;
    }

    return descriptors.map((descriptor) => ({
        type: descriptor.type as PublicKeyCredentialType,
        id: toBuffer(descriptor.id),
        transports: descriptor.transports as
            AuthenticatorTransport[] | undefined,
    }));
}

/** Registration options as `GET /user/passkeys/options` answers them. */
export function toCreationOptions(
    json: PublicKeyCredentialCreationOptionsJSON,
): PublicKeyCredentialCreationOptions {
    return {
        rp: json.rp,
        user: {
            id: toBuffer(json.user.id),
            name: json.user.name,
            displayName: json.user.displayName,
        },
        challenge: toBuffer(json.challenge),
        pubKeyCredParams: json.pubKeyCredParams,
        timeout: json.timeout,
        excludeCredentials: toDescriptors(json.excludeCredentials),
        authenticatorSelection: json.authenticatorSelection,
        attestation: json.attestation as AttestationConveyancePreference,
        extensions: json.extensions as
            AuthenticationExtensionsClientInputs | undefined,
    };
}

/** Login options as `GET /passkeys/login/options` answers them. */
export function toRequestOptions(
    json: PublicKeyCredentialRequestOptionsJSON,
): PublicKeyCredentialRequestOptions {
    return {
        challenge: toBuffer(json.challenge),
        timeout: json.timeout,
        rpId: json.rpId,
        allowCredentials: toDescriptors(json.allowCredentials),
        userVerification: json.userVerification as
            UserVerificationRequirement | undefined,
        extensions: json.extensions as
            AuthenticationExtensionsClientInputs | undefined,
    };
}

/**
 * What `navigator.credentials.create()` hands back, structurally, so the
 * serialiser can be tested without a DOM `PublicKeyCredential`.
 */
export type AttestationCredentialLike = {
    id: string;
    rawId: ArrayBuffer;
    type: string;
    authenticatorAttachment?: string | null;
    response: {
        clientDataJSON: ArrayBuffer;
        attestationObject: ArrayBuffer;
        getTransports?: () => string[];
    };
    getClientExtensionResults?: () => ExtensionResultsJSON;
};

/** Likewise for `navigator.credentials.get()`. */
export type AssertionCredentialLike = {
    id: string;
    rawId: ArrayBuffer;
    type: string;
    authenticatorAttachment?: string | null;
    response: {
        clientDataJSON: ArrayBuffer;
        authenticatorData: ArrayBuffer;
        signature: ArrayBuffer;
        userHandle: ArrayBuffer | null;
    };
    getClientExtensionResults?: () => ExtensionResultsJSON;
};

/** The body `POST /user/passkeys` validates (`PasskeyRegistrationRequest`). */
export function serializeAttestation(
    credential: AttestationCredentialLike,
): RegistrationResponseJSON {
    return {
        id: credential.id,
        rawId: base64UrlEncode(credential.rawId),
        type: credential.type as 'public-key',
        authenticatorAttachment:
            credential.authenticatorAttachment ?? undefined,
        response: {
            clientDataJSON: base64UrlEncode(credential.response.clientDataJSON),
            attestationObject: base64UrlEncode(
                credential.response.attestationObject,
            ),
            transports: credential.response.getTransports?.() ?? [],
        },
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
    };
}

/** The body `POST /passkeys/login` validates (`PasskeyVerificationRequest`). */
export function serializeAssertion(
    credential: AssertionCredentialLike,
): AuthenticationResponseJSON {
    return {
        id: credential.id,
        rawId: base64UrlEncode(credential.rawId),
        type: credential.type as 'public-key',
        authenticatorAttachment:
            credential.authenticatorAttachment ?? undefined,
        response: {
            clientDataJSON: base64UrlEncode(credential.response.clientDataJSON),
            authenticatorData: base64UrlEncode(
                credential.response.authenticatorData,
            ),
            signature: base64UrlEncode(credential.response.signature),
            userHandle:
                credential.response.userHandle === null
                    ? undefined
                    : base64UrlEncode(credential.response.userHandle),
        },
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
    };
}

/**
 * Why a ceremony did not produce a credential, as the page tells it. The
 * `DOMException` names come from the WebAuthn spec: `NotAllowedError` is the
 * person dismissing the prompt or the timeout, `InvalidStateError` an
 * authenticator that already holds a passkey for this account (it was in
 * `excludeCredentials`).
 */
export type CeremonyFailure =
    | 'unsupported'
    | 'password-confirmation-required'
    | 'cancelled'
    | 'already-registered'
    | 'failed';

export function classifyCeremonyError(error: unknown): CeremonyFailure {
    const name =
        typeof error === 'object' && error !== null && 'name' in error
            ? String((error as { name: unknown }).name)
            : '';

    if (name === 'NotAllowedError' || name === 'AbortError') {
        return 'cancelled';
    }

    if (name === 'InvalidStateError') {
        return 'already-registered';
    }

    return 'failed';
}

/** The sentence the page shows for a failure that is not the server's. */
export function ceremonyFailureMessage(failure: CeremonyFailure): string {
    switch (failure) {
        case 'unsupported':
            return 'This browser does not support passkeys.';
        case 'password-confirmation-required':
            return 'Confirm your password to continue.';
        case 'cancelled':
            return 'The passkey prompt was dismissed. Nothing was changed.';
        case 'already-registered':
            return 'This device already holds a passkey for your account.';
        case 'failed':
            return 'The passkey could not be used. Please try again.';
    }
}
