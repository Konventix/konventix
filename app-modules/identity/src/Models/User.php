<?php

declare(strict_types=1);

namespace Konventix\Identity\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Konventix\Identity\Data\AccountPreferencesData;
use Konventix\Identity\Database\Factories\UserFactory;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Mail\AuthMailer;
use Konventix\Identity\Profile\PlaceholderEmail;
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
 * @property string|null $password
 * @property string $username
 * @property string|null $tagline
 * @property string|null $status_emoji
 * @property array<string, string>|null $social_links
 * @property ProfileVisibility $profile_visibility
 * @property string $locale
 * @property string|null $timezone
 * @property AccountState $state
 * @property AccountPreferencesData $preferences
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'username', 'tagline', 'status_emoji', 'social_links', 'profile_visibility', 'locale', 'timezone', 'preferences'])]
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
            'social_links' => 'array',
            'profile_visibility' => ProfileVisibility::class,
            'state' => AccountState::class,
            'preferences' => AccountPreferencesData::class.':default',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The ways into this account that are not an email and a password.
     *
     * The one relation on this model that points at another table, and it
     * stays because it points at Identity's own — social login is
     * authentication, which is what this module owns. It is not the thin end
     * of the wedge the class docblock warns about; a relation to another
     * *module's* table still belongs in that module's read model.
     *
     * @return HasMany<SocialIdentity, $this>
     */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /**
     * Whether this account can be reached by password at all.
     *
     * False for an account created through a social provider and never given
     * one. Callers use this rather than testing `password !== null` so the
     * question reads the way it is meant: "is there a password login here?"
     */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /**
     * Whether the address on this row is a stand-in rather than one the
     * person gave us (#293). Such an account can sign in — through Steam,
     * which is how it got here — but is asked for a real address before it
     * reaches anything behind `verified`, and no mail is ever sent to it.
     */
    public function hasPlaceholderEmail(): bool
    {
        return PlaceholderEmail::is($this->email);
    }

    /**
     * Resolve the factory explicitly: Laravel's convention-based lookup only
     * works for models living under the `App\Models` namespace.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * ADR-0048 point 5: Laravel keeps generating and validating the signed
     * link (`URL::temporarySignedRoute('verification.verify', ...)`
     * unchanged); Messaging delivers it, branded and logged, instead of
     * Laravel's stock verification notification. Resolved per call —
     * `AuthMailer` is not injected here, this method is not constructor
     * context.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(AuthMailer::class)->sendVerification($this);
    }

    /**
     * ADR-0048 point 5: same substitution for the password-reset link —
     * Fortify's password broker still generates and hashes `$token`, only
     * delivery moves to Messaging.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        app(AuthMailer::class)->sendPasswordReset($this, $token);
    }
}
