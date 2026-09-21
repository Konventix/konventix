<?php

declare(strict_types=1);

namespace Konventix\Compliance\Bindings;

use Konventix\Compliance\Enums\PolicyBindingKind;

/**
 * The one value every consumer hands Compliance (ADR-0066, spec §5.3):
 * which brand, which event, which registration types, which entry
 * conditions, which settlement provider, and the act the resolution belongs
 * to.
 *
 * Scalars only, and built by whichever module owns the surface — never
 * interpreted by it. Commerce carries the registration-side ids as an opaque
 * bag it never reads (D-CPL-06); Registration builds the bag without knowing
 * that a policy exists. This class is Compliance's, so a module that wants
 * to *build* one hands over an array instead and lets the resolving side
 * construct it (`ofArray()`), which is what keeps the architecture test
 * green in both directions.
 *
 * A field left at its default means **the context does not know**, which is
 * not the same as "no restriction": a bound kind whose context value is
 * missing can never match (D-CPL-03), so a login — which knows no provider
 * and no event — resolves unbound policies only.
 */
final readonly class BindingContext
{
    /**
     * @param  list<string>  $registrationTypeIds
     * @param  list<string>  $entryConditionIds
     */
    public function __construct(
        public string $brandId,
        public ?string $eventId = null,
        public array $registrationTypeIds = [],
        public array $entryConditionIds = [],
        public ?string $settlementProvider = null,
        /** The act this resolution belongs to, for the evidence snapshot. */
        public ?string $registrationId = null,
        public ?string $orderId = null,
    ) {}

    /**
     * The brand and nothing else — what a login, a settings page or any
     * other surface with no purchase in hand knows.
     */
    public static function forBrand(string $brandId): self
    {
        return new self(brandId: $brandId);
    }

    /**
     * Rebuilds a context from the bag an event or an order line carried.
     *
     * Every key is optional and unknown keys are ignored: the bag travels
     * through modules that never interpret it, through a JSON column and
     * back, and a payload written by an older release must still resolve
     * rather than throw. A missing key means "not known", which is already
     * the honest reading (see the class docblock).
     *
     * Not named `from*`: laravel-data treats such a name as a magic creator
     * and this is not a `Data` class, but the convention is worth keeping
     * across the codebase (`.ai/rules/data.md`).
     *
     * @param  array<string, mixed>  $bag
     */
    public static function ofArray(array $bag, ?string $brandId = null): self
    {
        $brand = $brandId ?? (is_string($bag['brand_id'] ?? null) ? $bag['brand_id'] : null);

        return new self(
            brandId: (string) $brand,
            eventId: is_string($bag['event_id'] ?? null) ? $bag['event_id'] : null,
            registrationTypeIds: self::stringList($bag['registration_type_ids'] ?? null),
            entryConditionIds: self::stringList($bag['entry_condition_ids'] ?? null),
            settlementProvider: is_string($bag['settlement_provider'] ?? null) ? $bag['settlement_provider'] : null,
            registrationId: is_string($bag['registration_id'] ?? null) ? $bag['registration_id'] : null,
            orderId: is_string($bag['order_id'] ?? null) ? $bag['order_id'] : null,
        );
    }

    /**
     * The snapshot a consent row stores (D-CPL-09) and the shape the events
     * carry. Snake-cased, because it crosses module boundaries and lands in
     * a JSON column rather than in a `Data` object.
     *
     * @return array{brand_id: string, event_id: string|null, registration_type_ids: list<string>, entry_condition_ids: list<string>, settlement_provider: string|null, registration_id: string|null, order_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'brand_id' => $this->brandId,
            'event_id' => $this->eventId,
            'registration_type_ids' => $this->registrationTypeIds,
            'entry_condition_ids' => $this->entryConditionIds,
            'settlement_provider' => $this->settlementProvider,
            'registration_id' => $this->registrationId,
            'order_id' => $this->orderId,
        ];
    }

    /**
     * The context values a policy bound on this kind would have to match —
     * empty when the context knows nothing of that kind, which is what makes
     * such a binding unmatchable (D-CPL-03).
     *
     * @return list<string>
     */
    public function valuesFor(PolicyBindingKind $kind): array
    {
        return match ($kind) {
            PolicyBindingKind::Event => $this->eventId === null ? [] : [$this->eventId],
            PolicyBindingKind::RegistrationType => $this->registrationTypeIds,
            PolicyBindingKind::EntryCondition => $this->entryConditionIds,
            PolicyBindingKind::SettlementProvider => $this->settlementProvider === null ? [] : [$this->settlementProvider],
        };
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
