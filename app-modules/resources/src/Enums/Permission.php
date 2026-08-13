<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

/**
 * Resources' capability catalogue (ADR-0013), picked up by
 * `permissions:sync`.
 */
enum Permission: string
{
    case ViewResources = 'resources.view-resources';
    case ManageResourceTypes = 'resources.manage-resource-types';
    case ManageResources = 'resources.manage-resources';
    case BookOnBehalf = 'resources.book-on-behalf';
    case ReleaseBookings = 'resources.release-bookings';
}
