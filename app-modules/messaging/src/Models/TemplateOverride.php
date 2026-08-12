<?php

declare(strict_types=1);

namespace Konventix\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Messaging\Database\Factories\TemplateOverrideFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A brand's override of one template key for one locale. Either `subject` or
 * `body` may be null, in which case TemplateRenderer falls back to the
 * module-registered default for that field.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $template_key
 * @property string $locale
 * @property string|null $subject
 * @property string|null $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TemplateOverride extends Model
{
    /** @use HasFactory<TemplateOverrideFactory> */
    use BelongsToBrand, HasFactory, HasUlids;

    protected $table = 'messaging_template_overrides';

    protected $fillable = ['template_key', 'locale', 'subject', 'body'];

    protected static function newFactory(): TemplateOverrideFactory
    {
        return TemplateOverrideFactory::new();
    }
}
