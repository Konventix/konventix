<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Actions\VerifyAdmissionToken;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;

/**
 * KVX1 admission tokens (ADR-0033). Helper names are prefixed because Pest
 * helpers are process-global across every module's test files
 * (.ai/rules/tests.md).
 */
function tokenTestPayload(string $admissionId, string $eventId, array $dayIds = [], ?int $expiresAt = null): AdmissionTokenPayload
{
    return new AdmissionTokenPayload(
        admissionId: $admissionId,
        eventId: $eventId,
        nonce: (string) Str::ulid(),
        issuedAt: now()->getTimestamp(),
        expiresAt: $expiresAt ?? now()->addDays(30)->getTimestamp(),
        dayIds: $dayIds,
    );
}

it('round-trips a token through sign and verify', function (): void {
    $codec = new AdmissionTokenCodec;
    $pair = sodium_crypto_sign_keypair();

    $payload = tokenTestPayload((string) Str::ulid(), (string) Str::ulid(), ['day-1']);

    $token = $codec->encode($payload, 'abc123', base64_encode(sodium_crypto_sign_secretkey($pair)));

    expect($token)->toStartWith('KVX1.abc123.')
        ->and($codec->kidOf($token))->toBe('abc123');

    $verified = $codec->verify($token, base64_encode(sodium_crypto_sign_publickey($pair)));

    expect($verified)->not->toBeNull()
        ->and($verified->admissionId)->toBe($payload->admissionId)
        ->and($verified->eventId)->toBe($payload->eventId)
        ->and($verified->dayIds)->toBe(['day-1']);
});

it('rejects a token whose body was tampered with', function (): void {
    $codec = new AdmissionTokenCodec;
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));

    $token = $codec->encode(
        tokenTestPayload((string) Str::ulid(), (string) Str::ulid()),
        'abc123',
        base64_encode(sodium_crypto_sign_secretkey($pair)),
    );

    // Swap the body for one claiming a different admission.
    $forgedBody = rtrim(strtr(base64_encode(json_encode([
        'tid' => (string) Str::ulid(),
        'evt' => (string) Str::ulid(),
        'nonce' => 'x',
        'iat' => now()->getTimestamp(),
        'exp' => now()->addDay()->getTimestamp(),
        'days' => [],
    ])), '+/', '-_'), '=');

    $parts = explode('.', $token);
    $forged = implode('.', [$parts[0], $parts[1], $forgedBody, $parts[3]]);

    expect($codec->verify($forged, $public))->toBeNull();
});

it('rejects a token whose kid segment was rewritten', function (): void {
    // The signature covers the kid, so a token cannot be re-pointed at
    // another key by editing that segment.
    $codec = new AdmissionTokenCodec;
    $pair = sodium_crypto_sign_keypair();

    $token = $codec->encode(
        tokenTestPayload((string) Str::ulid(), (string) Str::ulid()),
        'abc123',
        base64_encode(sodium_crypto_sign_secretkey($pair)),
    );

    $parts = explode('.', $token);
    $rewritten = implode('.', [$parts[0], 'deadbeef', $parts[2], $parts[3]]);

    expect($codec->verify($rewritten, base64_encode(sodium_crypto_sign_publickey($pair))))->toBeNull();
});

it('rejects a token signed by a different key', function (): void {
    $codec = new AdmissionTokenCodec;
    $mine = sodium_crypto_sign_keypair();
    $theirs = sodium_crypto_sign_keypair();

    $token = $codec->encode(
        tokenTestPayload((string) Str::ulid(), (string) Str::ulid()),
        'abc123',
        base64_encode(sodium_crypto_sign_secretkey($theirs)),
    );

    expect($codec->verify($token, base64_encode(sodium_crypto_sign_publickey($mine))))->toBeNull();
});

it('reports an expired token as expired rather than forged', function (): void {
    $this->withBrand();

    $key = EntranceSigningKey::factory()->create();
    $codec = new AdmissionTokenCodec;

    $token = $codec->encode(
        tokenTestPayload((string) Str::ulid(), (string) Str::ulid(), expiresAt: now()->subHour()->getTimestamp()),
        $key->kid,
        $key->private_key,
    );

    $result = app(VerifyAdmissionToken::class)->handle($token, $key->brand_id);

    // "Not ours" and "ours but stale" are different things to tell door staff.
    expect($result['payload'])->not->toBeNull()
        ->and($result['expired'])->toBeTrue();
});

it('still verifies a token signed by a retired but unexpired key', function (): void {
    // Rotation must not invalidate QR codes already in attendees' wallets.
    $this->withBrand();

    $key = EntranceSigningKey::factory()->create();
    $codec = new AdmissionTokenCodec;

    $token = $codec->encode(
        tokenTestPayload((string) Str::ulid(), (string) Str::ulid()),
        $key->kid,
        $key->private_key,
    );

    $key->forceFill(['retired_at' => now()])->save();

    $result = app(VerifyAdmissionToken::class)->handle($token, $key->brand_id);

    expect($result['payload'])->not->toBeNull()
        ->and($result['expired'])->toBeFalse();
});

it('stops accepting a token once its key has expired out of the published set', function (): void {
    $this->withBrand();

    $key = EntranceSigningKey::factory()->create();
    $codec = new AdmissionTokenCodec;

    $token = $codec->encode(
        tokenTestPayload((string) Str::ulid(), (string) Str::ulid()),
        $key->kid,
        $key->private_key,
    );

    $key->forceFill(['retired_at' => now()->subMonth(), 'expires_at' => now()->subDay()])->save();

    expect(app(VerifyAdmissionToken::class)->handle($token, $key->brand_id)['payload'])->toBeNull();
});

it('rejects an unknown kid without touching cryptography', function (): void {
    $this->withBrand();

    $brand = EntranceSigningKey::factory()->create()->brand_id;

    expect(app(VerifyAdmissionToken::class)->handle('KVX1.nosuchkid.body.sig', $brand)['payload'])->toBeNull();
});

it('rotation retires the previous key and signs with the new one', function (): void {
    $brand = $this->withBrand();

    $first = app(RotateEntranceSigningKey::class)->handle($brand->id);
    $second = app(RotateEntranceSigningKey::class)->handle($brand->id);

    expect($first->fresh()->retired_at)->not->toBeNull()
        ->and($second->retired_at)->toBeNull()
        ->and($second->canSign())->toBeTrue()
        ->and($first->fresh()->canSign())->toBeFalse();

    $admission = Admission::factory()->create();
    $token = app(IssueAdmissionToken::class)->handle($admission);

    expect((new AdmissionTokenCodec)->kidOf($token))->toBe($second->kid);
});

it('refuses to issue a token when the brand has no active signing key', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->create();

    expect(fn () => app(IssueAdmissionToken::class)->handle($admission))
        ->toThrow(RuntimeException::class);
});

it('carries no holder identity in the token body', function (): void {
    // ADR-0033 keeps PII off venue hardware; the QR is the one channel that
    // could smuggle it there.
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create(['holder_user_id' => (string) Str::ulid()]);

    $token = app(IssueAdmissionToken::class)->handle($admission);
    $body = json_decode(base64_decode(strtr(explode('.', $token)[2], '-_', '+/')), true);

    expect(array_keys($body))->toEqualCanonicalizing(['tid', 'evt', 'nonce', 'iat', 'exp', 'days'])
        ->and(json_encode($body))->not->toContain($admission->holder_user_id);
});
