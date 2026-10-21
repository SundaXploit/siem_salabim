<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Installation State
    |--------------------------------------------------------------------------
    |
    | APP_INSTALLED is the portable installation flag. The marker provides a
    | second, filesystem-only signal so an existing installation stays closed
    | even when configuration is cached or its environment file is replaced.
    |
    */

    'installed' => (bool) env('APP_INSTALLED', false),

    'marker_path' => storage_path('app/private/installation/installed.json'),

    /*
    |--------------------------------------------------------------------------
    | One-time Installer Token
    |--------------------------------------------------------------------------
    |
    | Only a SHA-256 digest is persisted. The clear token is shown solely by
    | the explicit preparation command and must be supplied to the wizard.
    |
    */

    'token_path' => storage_path('app/private/installation/token.json'),

    'token_ttl_seconds' => max(300, (int) env('INSTALLER_TOKEN_TTL', 3600)),

    /*
    | Existing deployments predate APP_INSTALLED and the marker. A detected
    | administrator therefore closes the installer without changing data.
    */
    'detect_legacy_admin' => (bool) env('INSTALLER_DETECT_LEGACY_ADMIN', true),
];
