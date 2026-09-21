<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Models\Passkey;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Passkeys as a sign-in method (bugfix round 2026-09-21, Task 16). The
 * ceremonies themselves are Fortify's routes over `laravel/passkeys`; what
 * this module adds and these tests pin is the ULID model, the list on the
 * Security page, the 404 for another account's passkey, the consent gate on
 * the third login path, the account-state refusal, and the relying party
 * following the request host.
 *
 * No WebAuthn ceremony runs here: the package ships no attestation helper,
 * so `StorePasskey`/`VerifyPasskey` are faked in the container where a
 * store or a login is exercised, and the request bodies are credentials
 * that *deserialise* (the FormRequest insists on that) but would never
 * verify. What is therefore not covered: a real signature check, the
 * challenge/origin comparison, and the counter update — the package's own
 * suite has those.
 */
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::passkeys());
});

/**
 * A base64url helper the way the browser and the package spell it.
 */
function passkeyB64(string $raw): string
{
    return Base64UrlSafe::encodeUnpadded($raw);
}

/**
 * An assertion (`navigator.credentials.get()`) as the login page posts it:
 * the shape `Webauthn\AuthenticatorAssertionResponse` deserialises from.
 *
 * @return array<string, mixed>
 */
function passkeyAssertion(string $rawId, string $host = 'localhost'): array
{
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => passkeyB64(random_bytes(32)),
        'origin' => 'http://'.$host,
    ], JSON_THROW_ON_ERROR);

    $authenticatorData = hash('sha256', $host, true).chr(0x05).pack('N', 1);

    return [
        'id' => passkeyB64($rawId),
        'rawId' => passkeyB64($rawId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => passkeyB64($clientData),
            'authenticatorData' => passkeyB64($authenticatorData),
            'signature' => passkeyB64(random_bytes(64)),
            'userHandle' => passkeyB64(random_bytes(32)),
        ],
        'clientExtensionResults' => [],
    ];
}

/**
 * An attestation (`navigator.credentials.create()`) as the Security page
 * posts it: a `none`-format attestation object, hand-encoded as CBOR with
 * an ES256 public key, so `Webauthn\AuthenticatorAttestationResponse`
 * deserialises.
 *
 * @return array<string, mixed>
 */
function passkeyAttestation(string $rawId, string $host = 'localhost'): array
{
    $clientData = json_encode([
        'type' => 'webauthn.create',
        'challenge' => passkeyB64(random_bytes(32)),
        'origin' => 'http://'.$host,
    ], JSON_THROW_ON_ERROR);

    $coseKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20".random_bytes(32)."\x22\x58\x20".random_bytes(32);
    $attested = str_repeat("\0", 16).pack('n', strlen($rawId)).$rawId.$coseKey;
    $authenticatorData = hash('sha256', $host, true).chr(0x45).pack('N', 0).$attested;
    $cbor = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58".chr(strlen($authenticatorData)).$authenticatorData;

    return [
        'id' => passkeyB64($rawId),
        'rawId' => passkeyB64($rawId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => passkeyB64($clientData),
            'attestationObject' => passkeyB64($cbor),
            'transports' => ['internal'],
        ],
        'clientExtensionResults' => [],
    ];
}

function passkeyContractText(): PolicyVersion
{
    return PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create([
        'version' => 'terms-v1',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);
}

// --- Registration ---------------------------------------------------------

test('a signed-in person with a confirmed password gets registration options', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertOk()
        ->assertJsonPath('options.rp.id', 'localhost')
        ->assertJsonPath('options.user.name', $user->email)
        ->assertJsonStructure(['options' => ['challenge', 'pubKeyCredParams', 'authenticatorSelection']])
        ->assertSessionHas('passkey.registration_options');
});

test('registration options require a confirmed password', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('passkey.registration-options'))
        ->assertStatus(423);
});

test('adding a passkey requires a confirmed password', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->post(route('passkey.store'), [])
        ->assertRedirect(route('password.confirm'));
});

test('a passkey the browser attested is stored under the name the person gave it', function (): void {
    $user = User::factory()->create();
    $rawId = random_bytes(32);

    $this->mock(StorePasskey::class, function ($mock) use ($rawId): void {
        $mock->shouldReceive('__invoke')
            ->once()
            ->withArgs(fn (User $forUser, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): bool => $name === 'Work laptop'
                && $credential->rawId === $rawId)
            ->andReturnUsing(fn (User $forUser, string $name): Passkey => Passkey::factory()->for($forUser)->named($name)->withCredentialId($rawId)->create());
    });

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertOk();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->post(route('passkey.store'), [
            'name' => 'Work laptop',
            'credential' => passkeyAttestation($rawId),
        ])
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', 'passkey-registered');

    expect($user->passkeys()->where('name', 'Work laptop')->exists())->toBeTrue();
});

test('storing without the registration options in the session is refused, not a 500', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->post(route('passkey.store'), [
            'name' => 'Work laptop',
            'credential' => passkeyAttestation(random_bytes(32)),
        ])
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('credential');
});

// --- Listing and deleting ---------------------------------------------------

test('the security page lists the account\'s own passkeys and nobody else\'s', function (): void {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->named('Phone')->lastUsed()->create();
    Passkey::factory()->named('Somebody else\'s key')->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Security')
            ->where('canManagePasskeys', true)
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'Phone')
            ->has('passkeys.0.id')
            ->has('passkeys.0.lastUsedAt')
            ->has('passkeys.0.createdAt')
            ->missing('passkeys.0.credential'));
});

test('a passkey can be removed once the password is confirmed', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect(route('security.edit'));

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
});

test('removing a passkey without a confirmed password redirects to confirm password', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect(route('password.confirm'));

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
});

test('another account\'s passkey is not found rather than forbidden', function (): void {
    $user = User::factory()->create();
    $other = Passkey::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $other))
        ->assertNotFound();

    expect(Passkey::query()->whereKey($other->getKey())->exists())->toBeTrue();
});

test('an id that is not a passkey is not found either', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', ['passkey' => 'not-a-ulid']))
        ->assertNotFound();
});

// --- Login ----------------------------------------------------------------

test('a guest gets login options for a discoverable credential', function (): void {
    $this->getJson(route('passkey.login-options'))
        ->assertOk()
        ->assertJsonPath('options.rpId', 'localhost')
        ->assertJsonPath('options.userVerification', 'required')
        ->assertJsonStructure(['options' => ['challenge']])
        ->assertSessionHas('passkey.verification_options');
});

test('a passkey login lands on the dashboard and records the use', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $rawId = random_bytes(32);
    $passkey = Passkey::factory()->for($user)->withCredentialId($rawId)->create();

    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey): void {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->post(route('passkey.login'), ['credential' => passkeyAssertion($rawId)])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('a passkey login with an outstanding contract text goes to the consent screen', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $rawId = random_bytes(32);
    $passkey = Passkey::factory()->for($user)->withCredentialId($rawId)->create();
    passkeyContractText();

    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey): void {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->withSession(['url.intended' => 'http://localhost/settings/profile'])
        ->post(route('passkey.login'), ['credential' => passkeyAssertion($rawId)])
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', 'http://localhost/settings/profile');

    $this->assertAuthenticatedAs($user);
});

test('a passkey login whose contract texts are agreed proceeds as before', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $rawId = random_bytes(32);
    $passkey = Passkey::factory()->for($user)->withCredentialId($rawId)->create();
    $version = passkeyContractText();
    Consent::factory()->for($version, 'policyVersion')->create(['subject_id' => $user->getKey()]);

    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey): void {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->post(route('passkey.login'), ['credential' => passkeyAssertion($rawId)])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('a locked account cannot sign in with a passkey enrolled before the lock', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['state' => AccountState::Locked]);
    $rawId = random_bytes(32);
    $passkey = Passkey::factory()->for($user)->withCredentialId($rawId)->create();

    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey): void {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->from(route('login'))
        ->post(route('passkey.login'), ['credential' => passkeyAssertion($rawId)])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('credential');

    $this->assertGuest();
});

test('an anonymized account\'s passkey is refused without saying why', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['state' => AccountState::Anonymized]);
    $rawId = random_bytes(32);
    $passkey = Passkey::factory()->for($user)->withCredentialId($rawId)->create();

    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey): void {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->from(route('login'))
        ->post(route('passkey.login'), ['credential' => passkeyAssertion($rawId)])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('credential');

    $this->assertGuest();
});

test('a login without the verification options in the session is refused, not a 500', function (): void {
    $this->withBrand();

    $this->from(route('login'))
        ->post(route('passkey.login'), ['credential' => passkeyAssertion(random_bytes(32))])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('credential');

    $this->assertGuest();
});

// --- Relying party ----------------------------------------------------------

test('the relying party is the host the request came in on', function (): void {
    Brand::factory()->withDomain('sxlan.test')->create(['slug' => 'sxlan']);

    $this->getJson('http://sxlan.test/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('options.rpId', 'sxlan.test');

    expect(config('passkeys.relying_party_id'))->toBe('sxlan.test')
        ->and(config('passkeys.allowed_origins'))->toBe(['http://sxlan.test']);
});

test('the relying party on the operator console is the console host', function (): void {
    $brand = $this->withBrand();
    $brand->operator->forceFill(['admin_host' => 'admin.passkeys.test'])->save();

    $this->getJson('http://admin.passkeys.test/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('options.rpId', 'admin.passkeys.test');
});

// --- GDPR ------------------------------------------------------------------

test('a passkey is named in the account export without its credential', function (): void {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->named('Phone')->lastUsed()->create();

    $export = (new IdentityGdprHandler)->exportFor(SubjectRef::forUser((string) $user->getKey()));

    expect($export['passkeys'])->toHaveCount(1)
        ->and($export['passkeys'][0]['name'])->toBe('Phone')
        ->and($export['passkeys'][0])->not->toHaveKey('credential')
        ->and($export['passkeys'][0])->not->toHaveKey('credential_id');
});

test('anonymizing an account removes its passkeys outright', function (): void {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->create();
    $other = Passkey::factory()->create();

    (new IdentityGdprHandler)->delete(SubjectRef::forUser((string) $user->getKey()), DeletionMode::Anonymize);

    expect($user->passkeys()->count())->toBe(0)
        ->and(Passkey::query()->whereKey($other->getKey())->exists())->toBeTrue();
});
