<?php

declare(strict_types=1);

namespace Konventix\Event\Enums;

/**
 * Whether an unpublished event is reachable by direct link (SPEC.md "Event
 * visibility"). This is independent of lifecycle state: a `Draft` event with
 * `Unlisted` visibility is still reachable on its landing route, while a
 * `Draft` event with `Public` visibility 404s until it is published.
 */
enum EventVisibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
}
