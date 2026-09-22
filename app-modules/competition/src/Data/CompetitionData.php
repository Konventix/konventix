<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Carbon\CarbonImmutable;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Stage;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The competition form of the console (design §9): one `Data` object for the
 * competition **and its first stage** (§7.1), so the format — which lives on
 * the stage, not on the competition — is typed once and validated once
 * (ADR-0009).
 *
 * The lifecycle is deliberately absent. `status` moves only through the
 * lifecycle Actions and their transition table (§4); a form field would be a
 * second, unguarded way into the same state machine.
 *
 * The three moments are ISO-8601 instants with an offset, not wall-clock
 * digits: the browser resolves its `datetime-local` value against the
 * reader's own zone before sending, and the `…Instant()` helpers below
 * normalise whatever arrives to UTC, because the columns are zone-less and
 * Eloquent writes a Carbon in whatever zone it carries (#237,
 * .ai/rules/js.md). `startsAt` in particular is the field Agenda's
 * programme reads back through `CompetitionPublished` /
 * `CompetitionRescheduled`.
 */
#[TypeScript]
final class CompetitionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $stageSettings  the format's own ruleset; the format validates its keys
     */
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,

        #[Required, Ulid]
        public readonly string $disciplineId,

        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255), Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        public readonly string $slug,

        #[Required, StringType, Max(255)]
        public readonly string $stageName,

        /** A `StageFormat` value; the Action refuses one it does not know. */
        #[Required, StringType, Max(32)]
        public readonly string $stageFormat,

        #[Required]
        public readonly CheckInMode $checkInMode,

        /** Minutes before `starts_at` that a self-service check-in opens (§6). */
        #[Required, IntegerType, Min(0), Max(1440)]
        public readonly int $checkInOpensMinutesBefore,

        #[Required, BooleanType]
        public readonly bool $requiresEventCheckIn,

        #[Required]
        public readonly ResultSubmissionMode $resultSubmissionMode,

        #[Required]
        public readonly ProofPolicy $proofPolicy,

        /** Markdown, shown on the public competition page (§10). */
        #[Nullable, StringType, Max(20000)]
        public readonly ?string $description = null,

        #[Nullable, StringType, Max(20000)]
        public readonly ?string $rules = null,

        /** Null means no limit. Two is the smallest field that can play. */
        #[Nullable, IntegerType, Min(2), Max(4096)]
        public readonly ?int $maxEntrants = null,

        #[Nullable, Date]
        public readonly ?string $signupOpensAt = null,

        #[Nullable, Date]
        public readonly ?string $signupClosesAt = null,

        #[Nullable, Date]
        public readonly ?string $startsAt = null,

        #[Nullable]
        public readonly ?array $stageSettings = null,
    ) {}

    public function signupOpensAtInstant(): ?CarbonImmutable
    {
        return self::asInstant($this->signupOpensAt);
    }

    public function signupClosesAtInstant(): ?CarbonImmutable
    {
        return self::asInstant($this->signupClosesAt);
    }

    public function startsAtInstant(): ?CarbonImmutable
    {
        return self::asInstant($this->startsAt);
    }

    /**
     * The competition and its first stage, as the editor holds them. `Stage`
     * is optional because a competition created before §7.1 — or one whose
     * stage a later slice deleted — must still open in the form rather than
     * 500.
     */
    public static function ofCompetition(Competition $competition, ?Stage $stage): self
    {
        return new self(
            eventId: $competition->event_id,
            disciplineId: $competition->discipline_id,
            name: $competition->name,
            slug: $competition->slug,
            stageName: $stage === null ? 'Playoffs' : $stage->name,
            stageFormat: $stage === null ? 'single_elimination' : $stage->format,
            checkInMode: $competition->check_in_mode,
            checkInOpensMinutesBefore: $competition->check_in_opens_minutes_before,
            requiresEventCheckIn: $competition->requires_event_check_in,
            resultSubmissionMode: $competition->result_submission_mode,
            proofPolicy: $competition->proof_policy,
            description: $competition->description,
            rules: $competition->rules,
            maxEntrants: $competition->max_entrants,
            signupOpensAt: $competition->signup_opens_at?->toIso8601String(),
            signupClosesAt: $competition->signup_closes_at?->toIso8601String(),
            startsAt: $competition->starts_at?->toIso8601String(),
            stageSettings: $stage?->settings,
        );
    }

    private static function asInstant(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
