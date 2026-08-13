<?php

declare(strict_types=1);

namespace Konventix\Content\Enums;

/**
 * What kind of thing a `ContentEntry` is.
 *
 * A typed discriminator on one model rather than a table per kind: news, a
 * page and an announcement differ in how they are *presented*, not in what
 * they are made of, and three near-identical tables would have to be kept in
 * step forever.
 *
 * `Announcement` is the one with a contract attached — publishing it emits
 * `AnnouncementPublished`, which Messaging turns into notifications
 * (D-CNT-08).
 */
enum ContentType: string
{
    case News = 'news';
    case Page = 'page';
    case Announcement = 'announcement';
}
