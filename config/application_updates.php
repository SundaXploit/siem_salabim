<?php

return [
    // Kept for deployment/recovery guards used by ingestion and data exports.
    'runtime_marker' => storage_path('framework/application-update.lock'),
];
