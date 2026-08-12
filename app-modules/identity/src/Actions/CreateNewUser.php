<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Data\RegisterData;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's `CreatesNewUsers` contract (`RegisteredUserController::store()`
 * calls `$creator->create($request->all())`, then logs the returned user in
 * and fires `Registered` — which `RecordBrandMembership` listens to,
 * ADR-0016). Validation is `RegisterData::validateAndCreate()` rather than a
 * manual `Validator::make()` (contrast `ResetUserPassword`, which predates
 * this PR's Data objects) so the registration form has one source of
 * validation rules, Inertia props and TypeScript type (ADR-0009).
 *
 * `Fortify::createUsersUsing()` registers this class with `app()->singleton()`
 * (vendor/laravel/fortify/src/Fortify.php), so it is built once per Octane
 * worker (ADR-0002/octane-development skill) and reused across every
 * subsequent request on that worker — constructor-injecting `Request` here
 * would cache the first request that ever triggered the singleton forever.
 * `resolveLocale()` resolves the request lazily via the `request()` helper
 * instead, which Octane rebinds per request.
 */
final class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $data = RegisterData::validateAndCreate($input);

        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'locale' => $this->resolveLocale(),
            ]);

            // Identity's own contract event (SPEC.md), distinct from
            // Fortify's framework `Registered`: consumers project read
            // models from this scalar payload (ADR-0004). Registration
            // always runs inside a resolved brand (ResolveBrand fronts the
            // web group), so currentOrFail is safe here.
            event(new UserRegistered(
                userId: $user->id,
                brandId: app(BrandContext::class)->currentOrFail()->getKey(),
                email: $user->email,
                displayName: $user->name,
                locale: $user->locale,
                registeredAt: now()->toIso8601String(),
            ));

            return $user;
        });
    }

    /**
     * The request's negotiated language if it sent one, otherwise the app
     * default. No brand- or user-facing locale switcher exists yet (SPEC.md
     * open questions), so this is the only signal available today.
     */
    private function resolveLocale(): string
    {
        return request()->getPreferredLanguage() ?? (string) config('app.locale');
    }
}
