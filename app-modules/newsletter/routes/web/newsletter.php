<?php

declare(strict_types=1);

/*
 * Routes owned by the Newsletter module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:newsletter` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */
