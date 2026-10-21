<?php

return [
    // Keep exports outside the public web root. This may also point at an S3
    // disk configured with bucket-side encryption and private ACLs.
    'disk' => env('SIEM_EXPORT_DISK', 'local'),
    'directory' => trim((string) env('SIEM_EXPORT_DIRECTORY', 'siem-exports'), '/') ?: 'siem-exports',
    'temp_directory' => env('SIEM_EXPORT_TEMP_DIRECTORY', storage_path('app/private/siem-export-tmp')),
    'chunk_size' => min(5000, max(100, (int) env('SIEM_EXPORT_CHUNK_SIZE', 500))),
    'purge_max_rows' => min(10_000_000, max(1, (int) env('SIEM_EXPORT_PURGE_MAX_ROWS', 100_000))),
    'queue' => trim((string) env('SIEM_EXPORT_QUEUE', 'exports')) ?: 'exports',
    'require_async_queue' => filter_var(env('SIEM_EXPORT_REQUIRE_ASYNC_QUEUE', true), FILTER_VALIDATE_BOOL),
];
