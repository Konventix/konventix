<?php

declare(strict_types=1);

namespace Konventix\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Identity\Database\Factories\SocialIdentityFactory;

/**
 * One way into one account that is not an email and a password.
 *
 * Deliberately not brand-scoped — see the migration. The provider's own id is
 * personal data (a SteamID64 identifies a natural person), which is why the
 * GDPR handler exports these rows and deletes them outright on anonymization
 * rather than leaving a tombstone.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $nickname
 * @property string|null $avatar_url
 * @property Carbon $linked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SocialIdentity extends Model
{
    /** @use HasFactory<SocialIdentityFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['provider', 'provider_user_id', 'nickname', 'avatar_url', 'linked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): SocialIdentityFactory
    {
        return SocialIdentityFactory::new();
    }
}
