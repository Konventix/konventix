<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Database\Factories\BrandMailSettingsFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Messaging's own read/config model for a brand's mail sender identity and
 * transport (ADR-0028). Deliberately not columns on Platform's `Brand` —
 * transport credentials and their encryption are a Messaging concern, and
 * Platform's Brand model must not grow module-specific columns (ADR-0004).
 *
 * A brand with no row here has not configured a transport: MailChannel falls
 * back to the application's default mailer rather than failing (ADR-0028).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $from_name
 * @property string $from_address
 * @property string|null $reply_to
 * @property array<string, mixed>|null $transport
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BrandMailSettings extends Model
{
    /** @use HasFactory<BrandMailSettingsFactory> */
    use BelongsToBrand, HasFactory, HasUlids;

    protected $table = 'messaging_brand_settings';

    protected $fillable = ['from_name', 'from_address', 'reply_to', 'transport'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Credentials at rest, encrypted (ADR-0028). The column is `text`,
            // not `json` — the stored value is Crypt-encrypted ciphertext, not
            // plain JSON.
            'transport' => 'encrypted:array',
        ];
    }

    protected static function newFactory(): BrandMailSettingsFactory
    {
        return BrandMailSettingsFactory::new();
    }
}
