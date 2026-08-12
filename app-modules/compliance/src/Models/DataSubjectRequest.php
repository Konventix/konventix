<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\DataSubjectRequestFactory;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A data subject's export/deletion/rectification request and its workflow:
 * state, the statutory deadline, and — via `responses()` — which of the
 * registered GdprHandler modules have replied (SPEC "Owns").
 *
 * `subject_id` carries the same non-account-tolerant shape as Consent's; see
 * that model's docblock and decision-log Round 5 D-REG-08.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $subject_id
 * @property DataSubjectRequestType $type
 * @property DataSubjectRequestState $state
 * @property DeletionMode|null $mode
 * @property Carbon $deadline_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataSubjectRequest extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<DataSubjectRequestFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['subject_id', 'type', 'state', 'mode', 'deadline_at'];

    /**
     * Per-module response tracking (TODO.md "Next"): one row per registered
     * GdprHandler module, upserted by RecordModuleResponse.
     *
     * @return HasMany<DataSubjectRequestResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(DataSubjectRequestResponse::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DataSubjectRequestType::class,
            'state' => DataSubjectRequestState::class,
            'mode' => DeletionMode::class,
            'deadline_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DataSubjectRequestFactory
    {
        return DataSubjectRequestFactory::new();
    }
}
