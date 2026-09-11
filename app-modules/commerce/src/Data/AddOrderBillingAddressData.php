<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * The operator console's billing-address retrofit (#4):
 * `OrderAdminController::addBillingAddress()`. Field names and lengths
 * mirror `Konventix\Registration\Data\SubmitRegistrationData` — the same
 * `organizationName`/`recipientName` pairing, the same `Max`/`Size`
 * constraints on the address fields — so an operator who has filled one
 * billing form has filled the other, even though an order snapshots its
 * party as JSON (ADR-0048) rather than as Registration's flat columns.
 *
 * The whole party is resubmitted, not merged field by field — the same
 * shape Registration's own `UpdateBillingParty` uses — so this is an edit
 * form pre-filled with whatever the order already carries (name and email
 * from the payer's account, ADR-0048), not a bolt-on "just the address"
 * patch. Address fields are required here rather than nullable the way
 * `SubmitRegistrationData`'s are: this form exists specifically because an
 * address is missing, so an empty submission is never a "nothing to
 * declare" case the way an ordinary registration's optional billing fields
 * are.
 *
 * Inbound only, so no `#[TypeScript]` (mirrors `RefundOrderData`): nothing
 * renders this shape, the page posts the fields and reads its errors back
 * under their own names.
 */
final class AddOrderBillingAddressData extends Data
{
    public function __construct(
        #[Nullable, StringType, Max(255)]
        public readonly ?string $organizationName,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $recipientName,

        #[Required, StringType, Max(255)]
        public readonly string $addressLine,

        #[Required, StringType, Max(16)]
        public readonly string $postalCode,

        #[Required, StringType, Max(255)]
        public readonly string $city,

        #[Required, StringType, Size(2)]
        public readonly string $countryCode,

        #[Nullable, StringType, Max(32)]
        public readonly ?string $vatId,
    ) {}
}
