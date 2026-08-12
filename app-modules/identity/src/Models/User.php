<?php

declare(strict_types=1);

namespace Konventix\Identity\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Konventix\Identity\Data\AccountPreferencesData;
use Konventix\Identity\Database\Factories\UserFactory;
use Konventix\Identity\Enums\AccountState;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * The person behind an account: authentication, profile, preferences.
 *
 * KEEP THIS MODEL SLIM. LanCore's User imported models from 13 domains and grew
 * to 30 methods, and that is what blocked modularisation there
 * (lessons-from-lancore #2). Domain-specific relations do not belong here — a
 * module that needs to know something about a user maintains its own read model
 * (StafferProfile, ShopperProfile, ...) populated from Identity's events
 * (ADR-0004).
 *
 * Concretely: no registrations(), no orders(), no shifts(), no achievements().
 * If you are about to add a relation to another module's table, what you
 * actually want is a read model in that module.
 *
 * Users are deliberately NOT brand-scoped. A person may take part across
 * several brands; brand-specific facts about them live in the module that owns
 * that relationship.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $locale
 * @property string $timezone
 * @property AccountState $state
 * @property AccountPreferencesData $preferences
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale', 'timezone', 'preferences'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, MustVerifyEmailTrait, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'state' => AccountState::class,
            'preferences' => AccountPreferencesData::class.':default',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Resolve the factory explicitly: Laravel's convention-based lookup only
     * works for models living under the `App\Models` namespace.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
