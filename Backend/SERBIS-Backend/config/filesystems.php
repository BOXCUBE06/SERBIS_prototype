<?php

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

    /*
    |--------------------------------------------------------------------------
    | Upload Destinations
    |--------------------------------------------------------------------------
    |
    | Which disk each kind of upload goes to. Both default to the local disks,
    | so nothing changes in development.
    |
    | This exists because most hosts give a container an ephemeral filesystem:
    | anything written under storage/ is gone at the next deploy or restart.
    | Government ID scans are the ones that matter — a resident uploads one
    | once, cannot re-upload it, and the request it belongs to outlives the
    | deploy. Point these at 's3' (S3, R2, Spaces — same driver) or mount a
    | persistent volume at storage/app and leave them alone.
    |
    */

    'uploads' => [
        'private' => env('UPLOADS_PRIVATE_DISK', 'local'),
        'public' => env('UPLOADS_PUBLIC_DISK', 'public'),
    ],

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
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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

        // A SECOND bucket, and the reason it exists is worth stating plainly:
        // the two kinds of upload have opposite access rules. Government ID
        // scans are read only through an ownership-checked endpoint; info
        // materials are read by residents with no token at all. Pointing both
        // UPLOADS_*_DISK vars at 's3' would put them in one bucket under one
        // visibility, so either the ID scans become world-readable — the exact
        // exposure the private-read endpoint was built to end — or the info
        // materials stop resolving. Separate buckets make that mistake
        // impossible rather than merely discouraged.
        //
        // Credentials, region and endpoint are shared with 's3': one R2 API
        // token covers both buckets on the same account.
        's3_public' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PUBLIC_BUCKET'),
            // Required, not optional. Without it Storage::url() falls back to
            // building a URL from the API endpoint, which on R2 is the S3 API
            // host and answers 401 to a browser. Set it to the bucket's public
            // domain (r2.dev or a custom one).
            'url' => env('AWS_PUBLIC_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => false,
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
