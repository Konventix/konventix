<?php

declare(strict_types=1);

namespace Konventix\Support\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Database\Factories\SupportRequestFactory;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;

/**
 * One report (design §4.1). What somebody typed, which of the five
 * categories they picked, and the technical context of §5.
 *
 * **Deliberately not `BelongsToBrand`.** Every other brand-owned model in
 * this codebase carries the trait and its global scope (ADR-0010); this one
 * must not, and the reason is worth stating once here because it is the
 * single most surprising line in the module. `filed_on_brand_id` is
 * *provenance* (ADR-0050 §1) — which brand host the report arrived on — and
 * not a scope: the console inbox lists across every brand of the operator
 * and filters on the column explicitly, and a report filed on the operator
 * console has no brand at all (ADR-0063), which `BrandScope` would render
 * invisible rather than console-filed. On the admin host BrandScope
 * hardens to `WHERE 1=0` (`.ai/rules/app-modules-src.md`), so the trait
 * would have made the inbox permanently empty on the one host it is read
 * from. Scoping is therefore explicit and local: {@see scopeFiledOnBrand()}.
 *
 * The human reference is derived, never stored: the last eight characters
 * of the ULID, upper-cased, the convention Commerce uses for orders. One
 * identifier, so nothing can drift.
 *
 * The GDPR handler nulls `user_id` and `email` and overwrites `message`,
 * but never deletes the row: it is the operator's record of an incident,
 * and what is left says nothing about who reported it (§10.5).
 *
 * @property string $id
 * @property string|null $filed_on_brand_id
 * @property string|null $user_id
 * @property string|null $email
 * @property bool $contact_consent
 * @property list<string> $categories
 * @property string $message
 * @property SupportRequestState $state
 * @property bool $is_known_issue
 * @property string|null $known_issue_notice
 * @property string|null $external_reference
 * @property string|null $subject_module
 * @property string|null $subject_id
 * @property SupportContextData $context
 * @property string|null $assigned_to_user_id
 * @property CarbonImmutable|null $seen_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SupportRequest extends Model
{
    /** @use HasFactory<SupportRequestFactory> */
    use HasFactory, HasUlids;

    protected $table = 'support_requests';

    protected $fillable = [
        'filed_on_brand_id',
        'user_id',
        'email',
        'contact_consent',
        'categories',
        'message',
        'state',
        'is_known_issue',
        'known_issue_notice',
        'external_reference',
        'subject_module',
        'subject_id',
        'context',
        'assigned_to_user_id',
        'seen_at',
        'resolved_at',
        'closed_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            // A typed list, not a free-form blob (ADR-0009): the five cases
            // are a metric label and an alert selector.
            'categories' => 'array',
            'state' => SupportRequestState::class,
            'contact_consent' => 'boolean',
            'is_known_issue' => 'boolean',
            'context' => SupportContextData::class,
            'seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The categories as enum cases. The column is a JSON list of backed
     * values, so the cast alone would hand every caller raw strings.
     *
     * @return list<SupportCategory>
     */
    public function categoryCases(): array
    {
        return array_values(array_filter(array_map(
            SupportCategory::tryFrom(...),
            $this->categories,
        )));
    }

    /**
     * What a person quotes on the phone and what the alert annotation
     * prints: the last eight characters of the key, upper-cased.
     */
    public function reference(): string
    {
        return Str::upper(Str::substr((string) $this->getKey(), -8));
    }

    /**
     * The reporter as any surface may print them without holding
     * `support.view-reporter-contact` (§10.1). An address becomes
     *
     * `j***@example.com`; an account becomes "Account" because the display
     * name lives in Identity and this module may not read it (ADR-0004).
     */
    public function maskedReporter(): string
    {
        if ($this->user_id !== null) {
            return 'Account';
        }

        $email = $this->email;

        if ($email === null || $email === '') {
            return 'Guest';
        }

        $local = Str::before($email, '@');
        $domain = Str::after($email, '@');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * @return HasMany<SupportRequestMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportRequestMessage::class);
    }

    /**
     * The explicit scoping that replaces `BelongsToBrand` (class docblock).
     * Null asks for the console-filed rows, which is a question the trait
     * could not have expressed at all.
     *
     * @param  Builder<SupportRequest>  $query
     * @return Builder<SupportRequest>
     */
    public function scopeFiledOnBrand(Builder $query, ?string $brandId): Builder
    {
        return $brandId === null
            ? $query->whereNull('filed_on_brand_id')
            : $query->where('filed_on_brand_id', $brandId);
    }

    /**
     * Still waiting for somebody — what `support_requests_open{brand}`
     * counts.
     *
     * @param  Builder<SupportRequest>  $query
     * @return Builder<SupportRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', array_map(
            fn (SupportRequestState $state): string => $state->value,
            SupportRequestState::open(),
        ));
    }

    protected static function newFactory(): SupportRequestFactory
    {
        return SupportRequestFactory::new();
    }
}
