<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\CompetitionTeamFactory;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A team, per event (D-CMP-14). Cross-event identity stays Community
 * territory; an event's `predecessor_event_id` is what a later roster-import
 * UX would lean on rather than a team spanning years.
 *
 * A solo player is a team of size one (D-CMP-13) — one shape, so the bracket
 * engine never has to ask whether an entrant is a person or a squad.
 *
 * `invite_code` is the **only** self-service way onto a roster (design §5.2):
 * eight characters, unique within the brand, shared on Discord, regenerable
 * by the captain or an organizer when it leaks. There is no open join and no
 * request-to-join queue — a code somebody can revoke is the smaller mechanism
 * and the one LanCore's organizers actually used.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property string $invite_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompetitionTeam extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CompetitionTeamFactory> */
    use HasFactory, HasUlids;

    /**
     * Uppercase alphanumerics minus the four glyphs people mishear or mistype
     * (design §5.2): `0`/`O` and `1`/`I`. Thirty-two characters, so eight of
     * them are forty bits — plenty against guessing, while still being
     * readable over voice chat.
     */
    public const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const int CODE_LENGTH = 8;

    /**
     * `invite_code` is fillable so the factory can seed one — the column is
     * not nullable. No controller ever puts request input in it: a code is
     * issued by `CreateTeam` and replaced by `RegenerateInviteCode`, both of
     * which generate it themselves. `brand_id` stays out, as everywhere
     * (ADR-0010).
     */
    protected $fillable = ['event_id', 'name', 'invite_code'];

    /**
     * A fresh code. Not checked for collisions here — the caller writes it
     * under `competition_teams_brand_id_invite_code_unique` and retries,
     * because a read-then-write check loses exactly the race two simultaneous
     * regenerations create.
     */
    public static function generateInviteCode(): string
    {
        $alphabet = self::ALPHABET;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * Normalizes what somebody typed: people paste codes with spaces, in
     * lower case, and occasionally swap the excluded glyphs back in because
     * their eye insists the `0` was a zero. The last part is deliberate
     * forgiveness, not a widened alphabet — `0` can only ever have been `O`.
     */
    public static function normalizeInviteCode(string $code): string
    {
        return strtr(
            strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''),
            ['0' => 'O', '1' => 'I'],
        );
    }

    /**
     * @return HasMany<CompetitionTeamMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CompetitionTeamMember::class);
    }

    /**
     * @return HasMany<Entrant, $this>
     */
    public function entrants(): HasMany
    {
        return $this->hasMany(Entrant::class, 'competition_team_id');
    }

    /**
     * The roster as plain user ULIDs, oldest member first — the order
     * captaincy rotates in and the order the events carry.
     *
     * `withoutBrandScope()` per relation, not only on the parent: on the
     * admin host the scope hardens the *related* model's query to zero rows
     * too (.ai/rules/app-modules-src.md).
     *
     * @return list<string>
     */
    public function memberUserIds(): array
    {
        return array_values(array_map(strval(...), CompetitionTeamMember::withoutBrandScope()
            ->where('competition_team_id', $this->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('user_id')
            ->all()));
    }

    public function captain(): ?CompetitionTeamMember
    {
        /** @var CompetitionTeamMember|null */
        return CompetitionTeamMember::withoutBrandScope()
            ->where('competition_team_id', $this->getKey())
            ->where('role', TeamRole::Captain->value)
            ->first();
    }

    public function isCaptain(string $userId): bool
    {
        return CompetitionTeamMember::withoutBrandScope()
            ->where('competition_team_id', $this->getKey())
            ->where('user_id', $userId)
            ->where('role', TeamRole::Captain->value)
            ->exists();
    }

    public function hasMember(string $userId): bool
    {
        return CompetitionTeamMember::withoutBrandScope()
            ->where('competition_team_id', $this->getKey())
            ->where('user_id', $userId)
            ->exists();
    }

    protected static function newFactory(): CompetitionTeamFactory
    {
        return CompetitionTeamFactory::new();
    }
}
