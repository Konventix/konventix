<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\BadgeIssuanceFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A record that a badge was printed. Exists so `reprint` is a fact rather
 * than a guess — SPEC.md's "Owns" makes Admission responsible for "when it is
 * issued, and when it is reprinted", which is only answerable if the first
 * print left a trace.
 *
 * The PDF itself is not stored: a badge is reproducible on demand from the
 * admission and its template, so keeping attendee-bearing documents on disk
 * would add GDPR surface without adding capability. That is the opposite
 * choice from Commerce's invoices, which GoBD requires archived unaltered
 * (ADR-0031) — different obligations, different answer.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $admission_id
 * @property string|null $badge_template_id
 * @property string|null $issued_by_user_id
 * @property bool $reprint
 * @property Carbon $issued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BadgeIssuance extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<BadgeIssuanceFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'admission_id',
        'badge_template_id',
        'issued_by_user_id',
        'reprint',
        'issued_at',
    ];

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reprint' => 'boolean',
            'issued_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BadgeIssuanceFactory
    {
        return BadgeIssuanceFactory::new();
    }
}
