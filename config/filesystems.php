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

        /*
         | Bank statement files a human downloaded from their bank and uploaded
         | here. Kept off the public disk on purpose: a statement carries every
         | movement through the company's money, so it is not something to be
         | readable by whoever guesses a URL. The root sits under
         | storage/app/private, which storage/app/.gitignore excludes, so an
         | uploaded statement is never committed either.
         */
        'statements' => [
            'driver' => 'local',
            'root' => storage_path('app/private/bank-statements'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         | Rendered report exports. A balance sheet or a trial balance a human
         | asked for, written by a queued job and handed back only through a
         | short-lived signed route. Kept off the public disk for the same
         | reason a bank statement is: an export is the company's books in a
         | file, and it must not be readable by whoever guesses a URL. The root
         | sits under storage/app/private, which storage/app/.gitignore
         | excludes, so a rendered export is never committed either.
         */
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/private/exports'),
            'serve' => false,
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
