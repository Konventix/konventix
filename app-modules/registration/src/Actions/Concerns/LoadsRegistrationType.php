<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * Reads a registration's type on a path that may have no brand in context.
 *
 * Every Action here that reacts to a Commerce event finds its registration
 * through `Registration::withoutBrandScope()` (ADR-0010), and that is not
 * enough: the `registrationType` relation re-applies `BrandScope` on the
 * related model, which the operator admin host hardens to `WHERE 1=0`
 * (ADR-0029, `.ai/rules/app-modules-src.md`). The relation then reads null
 * and the Action dies on it.
 *
 * That is exactly what #236 was: the console's whole-order refund runs the
 * `order_refunded` consumer inside the operator's own request, so
 * `ReleasesQuotas` called `quotas()` on null one second after Stripe had
 * already sent the money back. The mail listeners had escaped the same trap
 * long before, per relation
 * (`MailOnRegistrationCancelled`: `->with(['registrationType' => fn ($q) =>
 * $q->withoutGlobalScope(BrandScope::class)])`); this trait is that escape
 * for the Action side, in one place so the two cannot drift.
 *
 * Only `BrandScope` is dropped, never every global scope: the type is read
 * through the registration's own relation, so the row it returns belongs to
 * that registration's brand by construction.
 */
trait LoadsRegistrationType
{
    private function registrationTypeOf(Registration $registration): RegistrationType
    {
        /** @var RegistrationType|null $loaded */
        $loaded = $registration->relationLoaded('registrationType')
            ? $registration->getRelation('registrationType')
            : null;

        if ($loaded instanceof RegistrationType) {
            return $loaded;
        }

        /** @var RegistrationType $type */
        $type = $registration->registrationType()
            ->withoutGlobalScope(BrandScope::class)
            ->firstOrFail();

        // Cached on the model, so a caller that reads the property later —
        // Model::shouldBeStrict() forbids the lazy load — finds it there.
        $registration->setRelation('registrationType', $type);

        return $type;
    }
}
