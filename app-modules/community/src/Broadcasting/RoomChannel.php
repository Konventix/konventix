<?php

declare(strict_types=1);

namespace Konventix\Community\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Konventix\Community\Enums\Permission;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Room;
use Konventix\Community\Providers\CommunityServiceProvider;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Modules\ModuleGate;

/**
 * Authorises one private per-room channel (design §8) — the shoutbox's only
 * realtime surface. `routes/channels.php` registers the pattern and points
 * at this class; the decision itself lives here, in the module that owns
 * the room.
 *
 * **The gate is Admission's answer, reached the sanctioned way.** Reading a
 * room requires admission to the event the room is attached to (design §6,
 * D-COM-03), and the question is put to Platform's `ActiveEventContext`,
 * which is a projection of Admission's own participant events (ADR-0004).
 * It is **never** put to `community_event_presence`: that table is a badge
 * Community displays and never enforces (ADR-0067 decision 2), and using a
 * display cache as an authorisation source is the precise mistake that ADR
 * exists to prevent. Nothing in this file reads it.
 *
 * **Moderators are the deliberate second set** (design §6, D-COM-04): an
 * organizer who was never scanned in must still be able to see the wall
 * they are moderating, so `Permission::ModerateCommunity` opens the channel
 * on its own. That is the one place where a room's audience and its
 * moderators differ, and §6 says so outright.
 *
 * Four things are checked, in the order a refusal is cheapest:
 *
 * 1. the room exists at all — an invented ULID is refused, not looked up;
 * 2. `community` is active for **the room's** brand (ADR-0003) — the
 *    `/broadcasting/auth` route carries no `ensure-module` middleware, so
 *    this is the only place that question can be asked of a subscription;
 * 3. the **resolved** brand is the room's brand. Both the capability check
 *    and the admission check answer for whichever brand the request
 *    resolved, so without this a moderator of brand A — or a participant
 *    admitted on brand A — would be handed brand B's room;
 * 4. capability, else admission — the capability for the `$user` the
 *    broadcaster authenticated, and the admission for that same person,
 *    asserted rather than assumed (see `isAdmittedToSubject()`).
 *
 * The two scoped bindings are resolved inside `join()` rather than
 * constructor-injected. Laravel `make()`s a channel class per authorisation
 * request, so an instance never outlives one, but `.ai/rules/providers.md`'s
 * rule stands for its own sake under Octane: a scoped binding is read at
 * call time, never stored on `$this`.
 */
final class RoomChannel
{
    /**
     * The channel pattern `routes/channels.php` registers. A ULID carries
     * no dot, so the single `{roomId}` placeholder — which Laravel matches
     * as `[^\.]+` — captures the whole id.
     */
    public const string PATTERN = 'community.room.{roomId}';

    /**
     * The channel name for one room, as both the broadcast side
     * (`ShoutboxMessagePosted::broadcastOn()`) and the client's Echo
     * subscription must spell it. One builder so the two cannot drift; the
     * `private-` prefix is `PrivateChannel`'s to add, not this method's.
     */
    public static function nameFor(string $roomId): string
    {
        return 'community.room.'.$roomId;
    }

    public function join(Authenticatable $user, string $roomId): bool
    {
        $room = Room::withoutBrandScope()->find($roomId);

        if (! $room instanceof Room) {
            return false;
        }

        if (! app(ModuleGate::class)->isActiveForBrand(CommunityServiceProvider::moduleName(), $room->brand_id)) {
            return false;
        }

        if (app(BrandContext::class)->id() !== $room->brand_id) {
            return false;
        }

        return $this->moderates($user) || $this->isAdmittedToSubject($user, $room);
    }

    /**
     * D-COM-04: capability alone, never admission, and never the badge.
     * The capability is brand-scoped (spatie teams, set by `ResolveBrand`),
     * which is why `join()` has already established that the resolved brand
     * is this room's.
     */
    private function moderates(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows(Permission::ModerateCommunity->value);
    }

    /**
     * D-COM-03: admitted to the running event this room is attached to.
     *
     * A room whose subject is not an event refuses everyone who is not a
     * moderator — `match` and `competition` ship in the enum with nothing
     * creating them (ADR-0067 "Consequences"), and answering "admitted?"
     * for a subject that is not an event would be inventing sub-project B's
     * access rule a release early.
     *
     * **The person is pinned, not assumed.** `ActiveEventContext::all()`
     * answers for the *ambient* viewer — it resolves `auth()->id()` itself
     * and memoises on brand plus that id — while the broadcaster hands this
     * class the `$user` it authenticated. Today the two are always the same
     * person, because `Broadcaster::retrieveUser()` returns
     * `$request->user()` when the registration passes no `guards` option
     * and `routes/channels.php` passes none. That is a property of one line
     * of configuration, not a guarantee: adding `['guards' => ['sanctum',
     * 'web']]` to support a token client lets `retrieveUser()` return
     * sanctum user B while the session cookie still authenticates user A,
     * and the capability half would then answer for B while this half
     * answered for A. So the identity is asserted here rather than
     * inherited from the ambient container, and a mismatch — including a
     * caller with no authenticated user at all, such as a console probe or
     * a test that forgets `actingAs` — refuses. Refusing is the safe
     * direction: a moderator still reaches the room through `moderates()`,
     * which was always explicit about whose capability it reads.
     */
    private function isAdmittedToSubject(Authenticatable $user, Room $room): bool
    {
        if ($room->subject_type !== RoomSubject::Event) {
            return false;
        }

        if (! $this->isTheAmbientViewer($user)) {
            return false;
        }

        return app(ActiveEventContext::class)
            ->all()
            ->contains(fn (ActiveEventData $event): bool => $event->eventId === $room->subject_id);
    }

    /**
     * Whether the user the broadcaster authenticated is the same person
     * `auth()` resolves — the precondition for reading an answer that was
     * computed for the ambient viewer.
     */
    private function isTheAmbientViewer(Authenticatable $user): bool
    {
        $viewer = auth()->id();
        $subject = $user->getAuthIdentifier();

        if ($viewer === null || ! is_scalar($subject)) {
            return false;
        }

        return (string) $viewer === (string) $subject;
    }
}
