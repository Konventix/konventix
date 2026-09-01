<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Konventix\Compliance\Database\Factories\DataSubjectRequestVerificationFactory;
use Konventix\Compliance\Enums\DataSubjectRequestType;

/**
 * An address held for one round-trip (ADR-0050 §6).
 *
 * @property string $id
 * @property string|null $filed_on_brand_id
 * @property string $email
 * @property string $type
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataSubjectRequestVerification extends Model
{
    /** @use HasFactory<DataSubjectRequestVerificationFactory> */
    use HasFactory, HasUlids;

    /** ADR-0050 §6: long enough for a mail read the next morning. */
    public const int TTL_HOURS = 24;

    protected $fillable = ['filed_on_brand_id', 'email', 'type', 'token_hash', 'expires_at'];

    /** @return array{0: self, 1: string} the row, and the plaintext token */
    public static function issue(string $email, DataSubjectRequestType $type, ?string $brandId): array
    {
        $plain = Str::random(64);

        $row = self::create([
            'filed_on_brand_id' => $brandId,
            'email' => mb_strtolower(trim($email)),
            'type' => $type,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return [$row, $plain];
    }

    public static function findByToken(#[\SensitiveParameter] string $plainToken): ?self
    {
        /** @var self|null */
        return self::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * @return array<string, class-string>
     */
    protected function casts(): array
    {
        return ['type' => DataSubjectRequestType::class, 'expires_at' => 'datetime'];
    }

    protected static function newFactory(): DataSubjectRequestVerificationFactory
    {
        return DataSubjectRequestVerificationFactory::new();
    }
}
