<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

/*
 * Host-level providers only.
 *
 * Modules are NOT listed here. Each module declares its own ServiceProvider in
 * its composer.json `extra.laravel.providers`, so adding a module never means
 * remembering to edit a central file (ADR-0003).
 */
return [
    AppServiceProvider::class,
    ObservabilityServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
];
