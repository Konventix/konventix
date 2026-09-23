<?php

declare(strict_types=1);

namespace Konventix\Competition\Seeding;

use Konventix\Competition\Enums\StageEntrySource;
use Konventix\Competition\Exceptions\StageDrawRefusedException;
use Konventix\Competition\Models\Stage;

/**
 * The desk's drag order (D-CMP-20, design §7.5).
 *
 * Constructed with the list the desk dragged, so it is the one source that is
 * never registered: `SeedSourceRegistry` answers "what does this stage's
 * `entry_source` mean by default", and a hand-made order is by definition not
 * a default. `SeedStage` takes it as an explicit override instead.
 *
 * ## Two decisions about an imperfect list
 *
 * An id that is **not in the field** is refused rather than dropped. A desk
 * sending an entrant who has since withdrawn, or one from another
 * competition, has a stale screen, and silently seeding fifteen of the
 * sixteen teams it thinks it seeded is the kind of wrong nobody notices until
 * the bracket is drawn. A repeated id is refused for the same reason.
 *
 * An entrant in the field that the list **leaves out** is appended behind the
 * given order, in the field's own stable order, rather than being left
 * unseeded. That order is `SignupField::entrantIds()`'s — seeded entrants by
 * their current seed, then the unseeded oldest first — read *before*
 * `SeedStage` clears the old seeds. So on a field that was already seeded,
 * the undragged entrants keep their relative order from the previous seeding,
 * not their creation order.
 *
 * A partial drag — "these four are the strong ones, sort the rest out
 * yourselves" — is a real thing a desk does, and a bracket missing everybody
 * who was not dragged is not what it asked for.
 */
final class ManualSeedSource implements SeedSource
{
    /**
     * @param  list<string>  $entrantIds  in the order the desk dragged them
     */
    public function __construct(
        private readonly array $entrantIds,
        private readonly SignupField $field = new SignupField,
    ) {}

    public function entrySource(): StageEntrySource
    {
        return StageEntrySource::Signup;
    }

    /**
     * @return list<string>
     */
    public function seedOrder(Stage $stage): array
    {
        $field = $this->field->entrantIds($stage);
        $inField = array_flip($field);

        $order = [];
        $seen = [];

        foreach ($this->entrantIds as $entrantId) {
            if (! isset($inField[$entrantId])) {
                throw new StageDrawRefusedException(sprintf(
                    'Entrant [%s] is not in stage [%s]\'s field and cannot be seeded into it.',
                    $entrantId,
                    $stage->id,
                ));
            }

            if (isset($seen[$entrantId])) {
                throw new StageDrawRefusedException(sprintf(
                    'Entrant [%s] appears twice in the seed order given for stage [%s].',
                    $entrantId,
                    $stage->id,
                ));
            }

            $seen[$entrantId] = true;
            $order[] = $entrantId;
        }

        foreach ($field as $entrantId) {
            if (! isset($seen[$entrantId])) {
                $order[] = $entrantId;
            }
        }

        return $order;
    }
}
