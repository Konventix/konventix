<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Admission\Models\EntranceSigningKey;

/**
 * Generates a new Ed25519 signing key for a brand and retires the previous
 * ones (ADR-0033).
 *
 * Retiring is not deleting. The outgoing keys keep their `expires_at`, so
 * they stay in the published key set and keep verifying every token they
 * already signed — deleting them at rotation would invalidate every QR in
 * every attendee's wallet at once.
 *
 * The new key's `expires_at` is deliberately longer than a token's lifetime,
 * so a token minted moments before rotation cannot outlive the key that
 * signed it.
 */
final class RotateEntranceSigningKey
{
    public const int KEY_LIFETIME_DAYS = IssueAdmissionToken::LIFETIME_DAYS + 30;

    public function handle(string $brandId): EntranceSigningKey
    {
        return DB::transaction(function () use ($brandId): EntranceSigningKey {
            EntranceSigningKey::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->whereNull('retired_at')
                ->update(['retired_at' => now()]);

            $pair = sodium_crypto_sign_keypair();

            $key = new EntranceSigningKey([
                'kid' => Str::lower(Str::random(16)),
                'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
                'activated_at' => now(),
                'expires_at' => now()->addDays(self::KEY_LIFETIME_DAYS),
            ]);
            // Explicit brand, not ambient context (ADR-0010).
            $key->forceFill(['brand_id' => $brandId])->save();

            return $key;
        });
    }
}
