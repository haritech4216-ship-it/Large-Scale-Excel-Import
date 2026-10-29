<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    | The number of rows to process and persist per database chunk.
    */
    'chunk_size' => (int) env('EXCEL_IMPORT_CHUNK_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Maximum Upload Size (Kilobytes)
    |--------------------------------------------------------------------------
    | Default: 102400 KB (100 MB).
    */
    'max_upload_size_kb' => (int) env('EXCEL_IMPORT_MAX_SIZE_KB', 102400),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk & Directory
    |--------------------------------------------------------------------------
    | Storage disk and private directory where uploaded spreadsheets are stored.
    */
    'disk' => env('EXCEL_IMPORT_DISK', 'local'),
    'directory' => env('EXCEL_IMPORT_DIRECTORY', 'imports'),

    /*
    |--------------------------------------------------------------------------
    | Stale Import Inactivity Timeout (Minutes)
    |--------------------------------------------------------------------------
    | Time in minutes of inactivity before an in-progress import is considered stale.
    */
    'stale_timeout_minutes' => (int) env('EXCEL_IMPORT_STALE_TIMEOUT_MINUTES', 15),
];
