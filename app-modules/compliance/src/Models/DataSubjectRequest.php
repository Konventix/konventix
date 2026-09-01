<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Konventix\Compliance\Database\Factories\DataSubjectRequestFactory;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * A data subject's export/deletion/rectification request and its workflow:
 * state, the statutory deadline, and — via `responses()` — which of the
 * registered GdprHandler modules have replied (SPEC "Owns").
 *
 * Not brand-scoped (ADR-0050 §1): a request is owed against the operator as
 * a single controller, not against one of its brands. `filed_on_brand_id`
 * carries provenance only — which brand's host the request arrived on.
 *
 * `subject_id`/`subject_email`: exactly one identifies the subject
 * (ADR-0050 §3) — an account-backed subject by ULID, an account-less one by
 * the address that is all we have for them. The database enforces this with
 * a check constraint; see `subject()`.
 *
 * @property string $id
 * @property string|null $filed_on_brand_id
 * @property string|null $subject_id
 * @property string|null $subject_email
 * @property DataSubjectRequestType $type
 * @property DataSubjectRequestState $state
 * @property DeletionMode|null $mode
 * @property Carbon $deadline_at
 * @property int $escalation_level
 * @property string|null $archive_path
 * @property string|null $archive_disk
 * @property Carbon|null $archive_expires_at
 * @property string|null $archive_token_hash
 * @property Carbon|null $closed_at
 * @property string|null $reason_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataSubjectRequest extends Model
{
    /** @use HasFactory<DataSubjectRequestFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'filed_on_brand_id',
        'subject_id',
        'subject_email',
        'type',
        'state',
        'mode',
        'deadline_at',
        'archive_path',
        'archive_disk',
        'archive_expires_at',
        'closed_at',
        'reason_code',
        'escalation_level',
    ];

    /**
     * Who this request is about (ADR-0050 §3). The check constraint
     * guarantees exactly one column is set, so this is total.
     */
    public function subject(): SubjectRef
    {
        return $this->subject_id !== null
            ? SubjectRef::forUser($this->subject_id)
            : SubjectRef::forEmail((string) $this->subject_email);
    }

    /**
     * An archive that exists and has not expired yet — the only state in
     * which a download may be served (see SubjectExportController).
     */
    public function hasDownloadableArchive(): bool
    {
        return $this->archive_path !== null
            && $this->archive_disk !== null
            && ($this->archive_expires_at === null || $this->archive_expires_at->isFuture());
    }

    /**
     * A single-use link for a subject who cannot sign in (ADR-0050 §9).
     * Returns the plaintext once; only the hash is stored. Consuming the
     * link clears the hash, so a forwarded mail is already spent.
     */
    public function issueArchiveToken(): string
    {
        $plain = Str::random(64);
        $this->forceFill(['archive_token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

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
            'escalation_level' => 'integer',
            'archive_expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DataSubjectRequestFactory
    {
        return DataSubjectRequestFactory::new();
    }
}
