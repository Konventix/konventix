<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

/**
 * Resources' capability catalogue (ADR-0013), picked up by
 * `permissions:sync`.
 *
 * `ManagePlans` is the seating console (design §8.2): creating, importing,
 * publishing and editing hall plans. Reservations on a plan's places are
 * `BookOnBehalf`, because an orga reservation is a booking like any other
 * (design §7.3); releasing one is `ReleaseBookings`.
 */
enum Permission: string
{
    case ViewResources = 'resources.view-resources';
    case ManageResourceTypes = 'resources.manage-resource-types';
    case ManageResources = 'resources.manage-resources';
    case ManagePlans = 'resources.manage-plans';
    case BookOnBehalf = 'resources.book-on-behalf';
    case ReleaseBookings = 'resources.release-bookings';
}
