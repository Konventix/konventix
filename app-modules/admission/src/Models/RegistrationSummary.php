<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A registration as Admission knows it (ADR-0004: this module may not read
 * Registration's models) — attendee-list design §3 and §4.4 (#329). Gives
 * the attendee list its ticket type label and the ticket detail the
 * registration's type and status.
 *
 * No factory: rows exist only through `Listeners\ProjectRegistrationSummary`,
 * so a test emits `RegistrationConfirmed`, `RegistrationCancelled` or
 * `RegistrationStandingAnnounced` rather than inventing a registration, as
 * with `EventSummary`.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_id
 * @property string|null $registration_type_id
 * @property string|null $type_name
 * @property string $status
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RegistrationSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'admission_registration_summaries';

    protected $fillable = ['registration_id', 'registration_type_id', 'type_name', 'status'];
}
