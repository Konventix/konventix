<?php

declare(strict_types=1);

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;
use Laravel\Socialite\Contracts\Factory;

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    |
    | This value determines the default "server" that will be used by Octane
    | when starting, restarting, or stopping your server via the CLI. You
    | are free to change this to the supported server of your choosing.
    |
    | Supported: "roadrunner", "swoole", "frankenphp"
    |
    */

    'server' => env('OCTANE_SERVER', 'roadrunner'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | When this configuration value is set to "true", Octane will inform the
    | framework that all absolute links must be generated using the HTTPS
    | protocol. Otherwise your links may be generated using plain HTTP.
    |
    */

    'https' => env('OCTANE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    |
    | All of the event listeners for Octane's events are defined below. These
    | listeners are responsible for resetting your application's state for
    | the next request. You may even add your own listeners to the list.
    |
    */

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            //
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // DisconnectFromDatabases::class,
            // CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm / Flush Bindings
    |--------------------------------------------------------------------------
    |
    | The bindings listed below will either be pre-warmed when a worker boots
    | or they will be flushed before every new request. Flushing a binding
    | will force the container to resolve that binding again when asked.
    |
    */

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        // Socialite's factory is a singleton that caches each driver, and a
        // driver holds the request it was built for. Left warm, the second
        // social login in a worker would verify the first one's parameters.
        // `Identity\Social\SteamProvider` re-sets the request defensively as
        // well; this covers anything else that reaches for Socialite directly.
        Factory::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Swoole Tables
    |--------------------------------------------------------------------------
    |
    | While using Swoole, you may define additional tables as required by the
    | application. These tables can be used to store data that needs to be
    | quickly accessed by other workers on the particular Swoole server.
    |
    */

    'tables' => [
        'example:1000' => [
            'name' => 'string:1000',
            'votes' => 'int',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Swoole Cache Table
    |--------------------------------------------------------------------------
    |
    | While using Swoole, you may leverage the Octane cache, which is powered
    | by a Swoole table. You may set the maximum number of rows as well as
    | the number of bytes per row using the configuration options below.
    |
    */

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watching
    |--------------------------------------------------------------------------
    |
    | The following list of files and directories will be watched when using
    | the --watch option offered by Octane. If any of the directories and
    | files are changed, Octane will automatically reload your workers.
    |
    | Every entry becomes a `watch <absolute path>` line in the generated
    | Caddyfile, and FrankenPHP's watcher recurses from the pattern's base
    | directory. So an entry is a promise about the whole tree under it, not
    | only about the files the glob matches: `bootstrap` also meant
    | `bootstrap/ssr`, rewritten by every `npm run build:ssr`, and a
    | recursive glob under `public` also meant `public/build`, rewritten by
    | every Vite build. Both are named file by file now (#195). Deliberately
    | absent, and to stay absent: `vendor`, `node_modules`, `storage`,
    | `.superpowers`, `public/build` and `bootstrap/ssr` — none of them is
    | code a worker executes, and all of them churn.
    |
    */

    'watch' => [
        // Short, and every entry a plain directory or a named file (#195).
        // FrankenPHP starts one watcher session per `watch` line in the
        // Caddyfile Octane generates, and its C++ watcher aborts with
        // `std::bad_alloc` (exit 134; supervisord respawns it and HTTP
        // answers "Empty reply from server" in the gap). Measured on
        // 2026-09-08 over two minutes of continuous edits: the previous
        // eleven entries — three of them recursive globs rooted at the same
        // `app-modules` tree — crashed 12 times; this list crashes 0 times
        // for edits under `app`, `config` or `routes`. Add an entry only for
        // code a *worker* executes, and never one whose tree another entry
        // already covers.
        //
        // Not watched, and not to be added: `vendor`, `node_modules`,
        // `storage`, `.superpowers`, `public/build`, `bootstrap/ssr` — none
        // of them is worker code and all of them churn. Nor `database` or
        // `composer.lock`: migrations, seeders and factories run in fresh
        // processes, and an install is rare enough to pay for a
        // `sail restart laravel.test`.
        'app',
        // All domain code lives in modules (.ai/rules/src.md) — without this
        // entry the dev web role serves stale module code until a manual
        // octane:reload, while fresh-process tests pass against the new code.
        // This is also the tree the watcher is closest to its limit on: one
        // measured run of edits confined here still cost four aborts, where
        // edits under `app` cost none and the mixed run cost none. README's
        // "The dev web server watches files" carries the numbers and says
        // how to trade the watching away when that is not good enough.
        'app-modules',
        // Named files, not the `bootstrap` directory: that also holds
        // `ssr/` (rewritten by every `npm run build:ssr`) and `cache/`.
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'config',
        'routes',
        '.env',
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection Threshold
    |--------------------------------------------------------------------------
    |
    | When executing long-lived PHP scripts such as Octane, memory can build
    | up before being cleared by PHP. You can force Octane to run garbage
    | collection if your application consumes this amount of megabytes.
    |
    */

    'garbage' => 50,

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Time
    |--------------------------------------------------------------------------
    |
    | The following setting configures the maximum execution time for requests
    | being handled by Octane. You may set this value to 0 to indicate that
    | there isn't a specific time limit on Octane request execution time.
    |
    */

    'max_execution_time' => 30,

    /*
    |--------------------------------------------------------------------------
    | Octane Server State File
    |--------------------------------------------------------------------------
    |
    | This value determines where Octane stores the state file used to track
    | the running server's master process ID and admin endpoint, which is
    | read by various Octane commands. You may tweak this if necessary.
    |
    */

    'state_file' => env('OCTANE_STATE_FILE', storage_path('logs/octane-server-state.json')),

];
