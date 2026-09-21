<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registration's registrant read model (SPEC.md "Consumes" — Identity's
 * events): display name and email for organizer-facing lists, keyed by
 * the user's own ULID, plus — ADR-0064 §6 — the `username` a name on an
 * event's attendee list is and `listed`, the person's opt-in to be named
 * there. Same projection shape as Messaging's `Recipient` —
 * deliberately NOT brand-scoped (accounts are operator-global, ADR-0016)
 * and NOT `HasUlids` (the key is assigned, never generated). No factory:
 * tests emit the Identity event.
 *
 * @property string $id
 * @property string $display_name
 * @property string $email
 * @property string|null $username
 * @property bool $listed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Registrant extends Model
{
    protected $table = 'registration_registrants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'display_name', 'email', 'username', 'listed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['listed' => 'boolean'];
    }
}
