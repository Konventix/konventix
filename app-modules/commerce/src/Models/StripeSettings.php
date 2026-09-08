<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Database\Factories\StripeSettingsFactory;

/**
 * The Stripe account this instance settles into (ADR-0058 point 2).
 * Deliberately NOT brand-owned and not a platform credential: Konventix is
 * not a marketplace, so one instance means one Stripe account, held by the
 * operator that runs it — the same per-operator row shape
 * `BookkeepingSettings` uses, and `forOperator()` hands back an unsaved
 * defaulted instance so the console form works before anyone has saved
 * anything.
 *
 * All three keys are `encrypted` (APP_KEY). Nothing here is ever logged,
 * exported or written to a page prop: the console is told *whether* a key
 * is set, its mode and its last four characters, which is what
 * `StripeCredentials` derives.
 *
 * @property string $id
 * @property string $operator_id
 * @property string|null $secret_key
 * @property string|null $webhook_secret
 * @property string|null $publishable_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StripeSettings extends Model
{
    /** @use HasFactory<StripeSettingsFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'operator_id',
        'secret_key',
        'webhook_secret',
        'publishable_key',
    ];

    /**
     * The casts below decrypt on read, so any `toArray()` — a log context, an
     * exception dump, a queued payload — would otherwise hand over all three in
     * cleartext. `Brand.reverb_app_secret` is the same defence for the same
     * reason. Reading the attribute directly still works; only serialisation
     * is closed, which is what this model's "whether, never what" promise
     * needs (ADR-0058 point 2).
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret_key',
        'webhook_secret',
        'publishable_key',
    ];

    public static function forOperator(string $operatorId): self
    {
        return self::query()->where('operator_id', $operatorId)->first()
            ?? new self(['operator_id' => $operatorId]);
    }

    /**
     * The instance's own row, whoever saved it — null when nobody has.
     *
     * Operator-agnostic on purpose: `StripeCredentials` is read from the
     * stateless `api` surface (the webhook) and from queue workers and
     * console commands, where no operator is resolved from a host
     * (ADR-0029), and an instance has exactly one operator by construction
     * (ADR-0001, ADR-0058). Ordered so two rows resolve deterministically
     * rather than by insertion luck.
     *
     * Two rows are ruled out by an instance having one operator and by
     * `operator_id` being unique per operator — but nothing in the schema
     * makes that invariant real, while the console's writers key on the
     * *resolved* operator and this reads instance-wide. If they ever
     * disagree, "Save" would write a row the webhook never reads and
     * "Clear keys" would report success with the old key still live. So the
     * second row is looked for rather than assumed away, and says so in the
     * log instead of failing silently. Two rows fetched, one used: the guard
     * costs no extra query.
     */
    public static function forInstance(): ?self
    {
        $rows = self::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(2)
            ->get();

        $row = $rows->first();

        if ($rows->count() > 1) {
            Log::warning('More than one stripe_settings row exists; an instance has exactly one operator (ADR-0001), so this is a data fault. Reading the oldest row — the console may be writing a different one.', [
                'reading' => $row?->getKey(),
            ]);
        }

        return $row;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // APP_KEY-encrypted at rest (ADR-0058 point 2), the same
            // custody rule `SettlementAccount.credentials` follows for
            // Mollie's and Unzer's brand keys (ADR-0039 point 4).
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'publishable_key' => 'encrypted',
        ];
    }

    protected static function newFactory(): StripeSettingsFactory
    {
        return StripeSettingsFactory::new();
    }
}
