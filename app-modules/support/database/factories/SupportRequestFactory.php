<?php

declare(strict_types=1);

namespace Konventix\Support\Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;

/**
 * @extends Factory<SupportRequest>
 */
class SupportRequestFactory extends Factory
{
    /** @var class-string<SupportRequest> */
    protected $model = SupportRequest::class;

    /**
     * A guest report on no brand — the shape with the fewest assumptions,
     * so a test has to say what it actually needs.
     *
     * `filed_on_brand_id` is deliberately null by default rather than
     * stamped from the brand context: this model is not `BelongsToBrand`
     * (see {@see SupportRequest}), and a factory that quietly invented a
     * brand would hide exactly the console-filed case the module exists to
     * handle.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filed_on_brand_id' => null,
            'user_id' => null,
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'contact_consent' => true,
            'categories' => [SupportCategory::Function->value],
            'message' => 'The page kept loading and then it showed an error I could not read.',
            'state' => SupportRequestState::Received,
            'is_known_issue' => false,
            'known_issue_notice' => null,
            'external_reference' => null,
            'subject_module' => null,
            'subject_id' => null,
            'context' => SupportContextData::ofNothing(),
            'assigned_to_user_id' => null,
            'seen_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Filed on a brand host — provenance, never a scope (ADR-0050 §1).
     */
    public function filedOnBrand(string $brandId): static
    {
        return $this->state(fn (): array => ['filed_on_brand_id' => $brandId]);
    }

    /**
     * Filed by a signed-in reporter. The address goes with it: a report
     * from an account carries no separate one (design §4.1).
     */
    public function byUser(string $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId, 'email' => null]);
    }

    public function byGuest(string $email): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'email' => Str::lower($email)]);
    }

    /**
     * @param  list<SupportCategory>  $categories
     */
    public function categories(array $categories): static
    {
        return $this->state(fn (): array => [
            'categories' => array_map(fn (SupportCategory $case): string => $case->value, $categories),
        ]);
    }

    /**
     * A state with its timestamps set consistently — a resolved row with no
     * `closed_at` would make the retention sweep untestable.
     */
    public function inState(SupportRequestState $state): static
    {
        return $this->state(fn (): array => [
            'state' => $state,
            'seen_at' => $state === SupportRequestState::Received ? null : now(),
            'resolved_at' => $state === SupportRequestState::Resolved ? now() : null,
            'closed_at' => $state->isTerminal() ? now() : null,
        ]);
    }

    /**
     * Closed long enough ago that `support:purge-requests` takes it.
     */
    public function closedAt(DateTimeInterface $closedAt): static
    {
        return $this->state(fn (): array => [
            'state' => SupportRequestState::Resolved,
            'seen_at' => $closedAt,
            'resolved_at' => $closedAt,
            'closed_at' => $closedAt,
        ]);
    }

    public function knownIssue(string $notice): static
    {
        return $this->state(fn (): array => [
            'is_known_issue' => true,
            'known_issue_notice' => $notice,
        ]);
    }

    public function context(SupportContextData $context): static
    {
        return $this->state(fn (): array => ['context' => $context]);
    }
}
