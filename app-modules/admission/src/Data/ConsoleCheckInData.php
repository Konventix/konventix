<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Konventix\Admission\Support\OwedDoorChecks;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the console's check-in dialog posts (attendee-list design §4.3,
 * D-ATT-04): the answer to each door check the ticket owes, `confirmed` or
 * `refused`, exactly as the door posts them. The rules mirror
 * `EntranceController::scans()`'s `conditions_checked`: at most twenty
 * answers, a key of at most 64 characters, one of the two outcomes.
 *
 * The event is never part of the payload: it is the ticket's own, read from
 * the admission the route names. Which keys may be answered is the ticket's
 * to say, so `ensureAnswersOwed()` checks them against `OwedDoorChecks`
 * once the ticket is found and scoped; `CheckInFromConsole` then checks the
 * same thing again under its lock, and a check left unanswered is its
 * refusal.
 */
#[TypeScript]
final class ConsoleCheckInData extends Data
{
    /**
     * @param  list<array{key: string, outcome: string}>  $conditions
     */
    public function __construct(
        #[LiteralTypeScriptType("Array<{ key: string; outcome: 'confirmed' | 'refused' }>")]
        public readonly array $conditions = [],
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'conditions' => ['sometimes', 'array', 'max:20'],
            'conditions.*.key' => ['required', 'string', 'max:64'],
            'conditions.*.outcome' => ['required', Rule::in(OwedDoorChecks::OUTCOMES)],
        ];
    }

    /**
     * Refuses an answer to a check the ticket does not owe today, on the
     * answer's own key (carried ruling: unknown keys are refused).
     *
     * @param  list<string>  $owedKeys
     *
     * @throws ValidationException
     */
    public function ensureAnswersOwed(array $owedKeys): void
    {
        $errors = [];

        foreach ($this->conditions as $index => $answer) {
            if (! in_array($answer['key'], $owedKeys, true)) {
                $errors["conditions.{$index}.key"] = __('This ticket does not owe that door check. Reload the ticket and try again.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
