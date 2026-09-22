<?php

declare(strict_types=1);

namespace Konventix\Agenda\Enums;

/**
 * The capability catalogue this module owns (ADR-0013, design §8). One
 * case is the whole list: composing the programme — items, tracks, places
 * and later the competition selection — is one job done by one person, and
 * splitting it would only produce grants nobody would ever hand out apart.
 *
 * Reading a published programme needs no capability at all: the page is
 * public.
 *
 * Picked up by `permissions:sync` through `PermissionCatalog`; add a case
 * and re-sync — never create permission rows by hand.
 */
enum Permission: string
{
    case ManageAgenda = 'agenda.manage-agenda';
}
