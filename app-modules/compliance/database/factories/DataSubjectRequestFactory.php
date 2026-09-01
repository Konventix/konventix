<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Models\DataSubjectRequest;

/**
 * @extends Factory<DataSubjectRequest>
 */
class DataSubjectRequestFactory extends Factory
{
    /** @var class-string<DataSubjectRequest> */
    protected $model = DataSubjectRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => (string) Str::ulid(),
            'subject_email' => null,
            'type' => DataSubjectRequestType::Export,
            'state' => DataSubjectRequestState::Received,
            'mode' => null,
            'deadline_at' => now()->addDays(30),
        ];
    }

    public function deletion(): static
    {
        return $this->state(fn (): array => [
            'type' => DataSubjectRequestType::Deletion,
            'mode' => DeletionMode::Anonymize,
        ]);
    }

    /**
     * An account-less subject (ADR-0050 §3): the address is all we have.
     */
    public function forEmail(string $email): static
    {
        return $this->state(fn (): array => [
            'subject_id' => null,
            'subject_email' => $email,
        ]);
    }
}
