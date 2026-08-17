<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Per-purpose disk selection
    |--------------------------------------------------------------------------
    |
    | Which disk NEW files of a given purpose are written to (ADR-0043). Each
    | purpose is pointed independently — a GoBD invoice archive and a brand
    | logo want different durability and delivery, which a single global
    | "public/private" pair could not express.
    |
    | Existing files are NOT affected: every media item records the disk it
    | was written to, so switching this leaves the library readable and
    | `storage:migrate` moves what already exists. A half-migrated library
    | still renders every file from wherever it currently lives.
    |
    */

    'media_disk' => env('MEDIA_DISK', 'media'),
    'invoices_disk' => env('INVOICES_DISK', 'invoices'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Platform's brand-scoped media library (ADR-0035): public web
         * assets (block images, sponsor logos, badge assets) served through
         * the storage symlink. Production may point this at S3/CDN — every
         * consumer resolves URLs through MediaLibrary::urlFor(), never a
         * hardcoded path.
         */
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage/media',
            'visibility' => 'public',
            // The web server serves these straight off the `public/storage`
            // symlink, so URLs need no application request (ADR-0043).
            'public_direct' => true,
            'throw' => true,
            'report' => false,
        ],

        /*
         * The same media library on S3-compatible object storage (ADR-0043).
         * `root` is a key prefix here, not a filesystem path: one public
         * bucket holds `media/{brandId}/…`.
         *
         * `public_direct` states whether objects are readable without
         * credentials — a bucket policy on Hetzner, website mode on Garage
         * (which implements neither bucket policies nor ACLs). It is a
         * deployment claim the application never infers; `storage:doctor`
         * verifies it. When false, media is served through the application's
         * own media route instead.
         *
         * Deliberately no `visibility` key: Laravel would then send an
         * `x-amz-acl` header on every write, and per-object ACLs are exactly
         * what Garage and R2 do not implement. Public readability is a
         * bucket-level deployment concern.
         */
        'media_s3' => [
            'driver' => 's3',
            'key' => env('AWS_MEDIA_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('AWS_MEDIA_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('AWS_MEDIA_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('AWS_MEDIA_BUCKET', env('AWS_BUCKET')),
            'url' => env('AWS_MEDIA_URL', env('AWS_URL')),
            'endpoint' => env('AWS_MEDIA_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_MEDIA_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'root' => env('AWS_MEDIA_PREFIX', 'media'),
            'public_direct' => env('AWS_MEDIA_PUBLIC_DIRECT', false),
            'throw' => true,
            'report' => false,
        ],

        /*
         * GoBD archive for issued invoice documents (ADR-0031): written once
         * by Commerce's GenerateInvoiceDocument job, never modified. Must
         * survive brand deletion and storage migrations for the statutory
         * ten-year retention (§147 AO) — point this at durable storage in
         * production.
         */
        'invoices' => [
            'driver' => 'local',
            'root' => storage_path('app/invoices'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        /*
         * The same archive on S3-compatible object storage (ADR-0043).
         * Deliberately carries no `public_direct` and no `url`: archived
         * invoices are streamed by Commerce's own authenticated controller,
         * never linked to, and never presigned — authorization stays on the
         * Action rather than on a URL with a lifetime of its own.
         *
         * WORM is a property of the bucket, not of this configuration: create
         * it with Object Lock where the provider supports it (Hetzner does,
         * but only at creation time; Garage implements neither Object Lock
         * nor versioning). ADR-0031's unalterability is met in the
         * application — an issued document is never rewritten.
         */
        'invoices_s3' => [
            'driver' => 's3',
            'key' => env('AWS_INVOICES_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('AWS_INVOICES_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('AWS_INVOICES_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('AWS_INVOICES_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_INVOICES_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_INVOICES_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'root' => env('AWS_INVOICES_PREFIX', 'invoices'),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
