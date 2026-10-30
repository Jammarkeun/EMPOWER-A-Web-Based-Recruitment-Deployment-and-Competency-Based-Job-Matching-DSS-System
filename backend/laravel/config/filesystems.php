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
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Applicant documents, evidence and photos on this machine.
        |
        | The local counterpart of the private Supabase bucket. Files live
        | outside the web root (storage/app/private/documents), so the web
        | server cannot hand them out. They are opened only through links
        | signed for a few minutes by DocumentStorageService and served by
        | DocumentFileController at /api/v1/document-files; an unsigned,
        | altered or expired link is refused. This replaces the `public` disk,
        | whose files anybody could open from /storage/... for ever.
        |
        | Not `serve => true`: Laravel's own file route sends a CSP `sandbox`
        | header, under which Chrome will not display a PDF.
        */
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'visibility' => 'private',
            'throw' => true,
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
        | Supabase Storage, addressed through its S3-compatible endpoint so that
        | applicant documents use the standard Storage facade, including
        | temporaryUrl() for expiring download links.
        |
        | The bucket is private. Birth certificates, police clearances, and
        | medical results are sensitive personal information under RA 10173, so
        | files are never served from a public URL - only through short-lived
        | signed URLs minted server-side.
        |
        | Path-style addressing is required: Supabase does not support the
        | virtual-hosted bucket subdomains that AWS uses.
        */
        'supabase' => [
            'driver' => 's3',
            'key' => env('SUPABASE_S3_ACCESS_KEY_ID'),
            'secret' => env('SUPABASE_S3_SECRET_ACCESS_KEY'),
            'region' => env('SUPABASE_S3_REGION', 'ap-southeast-1'),
            'bucket' => env('SUPABASE_S3_BUCKET', 'applicant-documents'),
            'endpoint' => env('SUPABASE_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
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
