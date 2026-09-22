<?php

declare(strict_types=1);

namespace Konventix\Community\Enums;

/**
 * Community's capability catalogue (ADR-0013). One case, and deliberately
 * one: D-COM-08's three verbs — remove a line, mute a person, lock the room
 * — are one job done by one person at one desk, and a grant that let
 * somebody lock the wall but not remove the line that made them lock it
 * would be a grant nobody hands out.
 *
 * **This capability is the whole moderation gate** (D-COM-04, spec §6):
 * moderation is gated on capability and *not* on admission, because an
 * organizer at a desk who was never scanned in must still be able to act.
 * Nothing here is ever checked against `community_event_presence` — that
 * table is a badge Community displays and never enforces (ADR-0067
 * decision 2).
 *
 * Picked up by `permissions:sync` through `PermissionCatalog`; add a case
 * and re-sync — never create permission rows by hand.
 */
enum Permission: string
{
    case ModerateCommunity = 'community.moderate-community';
}
