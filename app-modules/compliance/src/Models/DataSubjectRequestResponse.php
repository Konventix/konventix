<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\DataSubjectRequestResponseFactory;
use Konventix\Compliance\Enums\ModuleResponseState;

/**
 * One registered module's response to a DataSubjectRequest's fan-out — the
 * per-module response tracking the SPEC calls for (design choice: a row per
 * module rather than a JSON map, see TODO.md). One row per
 * (request, module), upserted by RecordModuleResponse — which is what makes
 * recording a response idempotent under retry or re-dispatch.
 *
 * Not brand-scoped on its own: it is always reached through its parent
 * DataSubjectRequest, which already carries the brand — the same shape as
 * Platform's BrandDomain relative to Brand.
 *
 * @property string $id
 * @property string $data_subject_request_id
 * @property string $module
 * @property ModuleResponseState $state
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataSubjectRequestResponse extends Model
{
    /** @use HasFactory<DataSubjectRequestResponseFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['data_subject_request_id', 'module', 'state', 'responded_at'];

    /**
     * @return BelongsTo<DataSubjectRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DataSubjectRequest::class, 'data_subject_request_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ModuleResponseState::class,
            'responded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DataSubjectRequestResponseFactory
    {
        return DataSubjectRequestResponseFactory::new();
    }
}
