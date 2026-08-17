<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Models\AuditLogEntry;

/**
 * @extends Factory<AuditLogEntry>
 */
class AuditLogEntryFactory extends Factory
{
    /** @var class-string<AuditLogEntry> */
    protected $model = AuditLogEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => (string) Str::ulid(),
            'action' => 'compliance.policy.published',
            'subject_id' => (string) Str::ulid(),
            'brand_id' => (string) Str::ulid(),
            'context' => [],
            'occurred_at' => now(),
        ];
    }
}
