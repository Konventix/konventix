<?php

declare(strict_types=1);

namespace Konventix\Admission\Tokens;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Encodes and verifies the `KVX1.<kid>.<body>.<sig>` admission token —
 * Ed25519 over the encoded body, generalized from LanCore's `LCT1`.
 *
 * **Deliberately database-free.** It takes raw key material as arguments and
 * returns values; it never loads a model, never touches a connection, never
 * reads ambient brand context. ADR-0033 makes local signature verification
 * the thing that grants admission at a door with no uplink, so the same logic
 * has to run unchanged inside the Slice 6 offline client. Anything that
 * reaches for the database here would not port.
 *
 * Verification answers *authenticity and expiry only*. Revocation and
 * already-checked-in are negative facts that a signature cannot carry, which
 * is the entire reason ADR-0033 pairs this with the delta feed.
 */
final class AdmissionTokenCodec
{
    public const string PREFIX = 'KVX1';

    public function encode(
        AdmissionTokenPayload $payload,
        string $kid,
        #[SensitiveParameter] string $privateKeyBase64,
    ): string {
        $body = $this->base64UrlEncode(
            json_encode($payload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        $privateKey = base64_decode($privateKeyBase64, true);

        // A key that will not decode to the right length is a misconfigured
        // brand, not a malformed token — fail loudly here rather than minting
        // something no device can ever verify.
        if ($privateKey === false || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'Entrance signing key [%s] is not a usable Ed25519 secret key.',
                $kid,
            ));
        }

        $signature = sodium_crypto_sign_detached($this->signingInput($kid, $body), $privateKey);

        return implode('.', [self::PREFIX, $kid, $body, $this->base64UrlEncode($signature)]);
    }

    /**
     * The `kid` a token claims, without trusting anything else about it — the
     * caller needs this to look up which public key to verify against, before
     * any verification has happened.
     */
    public function kidOf(string $token): ?string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || $parts[1] === '') {
            return null;
        }

        return $parts[1];
    }

    /**
     * Verifies the signature and returns the payload, or null if the token is
     * malformed or forged. Expiry is checked separately by the caller so a
     * device can distinguish "not ours" from "ours but stale" — they are very
     * different things to show door staff.
     *
     * The signature covers the `kid` as well as the body, so a token cannot
     * be re-pointed at a different key by rewriting that segment.
     */
    public function verify(string $token, string $publicKeyBase64): ?AdmissionTokenPayload
    {
        $parts = explode('.', $token);

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return null;
        }

        [, $kid, $body, $signature] = $parts;

        $rawSignature = $this->base64UrlDecode($signature);
        $publicKey = base64_decode($publicKeyBase64, true);

        // Unlike signing, a bad public key here returns null rather than
        // throwing: this path runs on whatever a scanner presents, and an
        // unverifiable token is a rejected scan, not a server error.
        if (
            $rawSignature === null
            || $publicKey === false
            || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            return null;
        }

        $valid = sodium_crypto_sign_verify_detached(
            $rawSignature,
            $this->signingInput($kid, $body),
            $publicKey,
        );

        if (! $valid) {
            return null;
        }

        $decoded = $this->base64UrlDecode($body);

        if ($decoded === null) {
            return null;
        }

        /** @var array<string, mixed>|null $decodedBody */
        $decodedBody = json_decode($decoded, true);

        if (! is_array($decodedBody)) {
            return null;
        }

        return AdmissionTokenPayload::ofDecodedBody($decodedBody);
    }

    private function signingInput(string $kid, string $body): string
    {
        return self::PREFIX.'.'.$kid.'.'.$body;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
