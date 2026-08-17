<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\AuditLogEntryFactory;

/**
 * One recorded privileged action (SPEC "Owns" — the audit trail).
 *
 * No `BelongsToBrand`: the trail spans brands and records actions taken on
 * the operator host where no brand exists (see the migration). `brand_id` is
 * data on the row.
 *
 * Append-only by convention rather than by constraint — nothing in the
 * application updates or deletes a row, and the one thing that ever will is
 * the retention job.
 *
 * @property string $id
 * @property string|null $actor_user_id
 * @property string $action
 * @property string|null $subject_id
 * @property string|null $brand_id
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditLogEntry extends Model
{
    /** @use HasFactory<AuditLogEntryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_id',
        'brand_id',
        'context',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AuditLogEntryFactory
    {
        return AuditLogEntryFactory::new();
    }
}
