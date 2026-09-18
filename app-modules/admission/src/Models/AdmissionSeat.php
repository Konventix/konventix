<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The place an admission sits on, as Admission knows it (#292 §5, ADR-0004:
 * this module may not read Resources' models, so it projects the one fact
 * the ticket needs — a place's label — off Resources' booking events).
 * Maintained by `Listeners\ProjectAdmissionSeat`; no factory, a test emits
 * `ResourceBookingConfirmed` rather than inventing a seat.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $admission_id
 * @property string $booking_id
 * @property string|null $resource_type_id
 * @property string|null $place_ref
 * @property string $place_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdmissionSeat extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = ['admission_id', 'booking_id', 'resource_type_id', 'place_ref', 'place_label'];
}
