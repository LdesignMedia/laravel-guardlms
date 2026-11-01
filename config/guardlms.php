<?php

return [
    'api_url' => env('GUARDLMS_API_URL', 'https://dashboard.guardlms.com'),
    'api_key' => env('GUARDLMS_API_KEY'),
    'site_url' => env('GUARDLMS_SITE_URL', env('APP_URL')),
    'enabled' => env('GUARDLMS_ENABLED', false),
    'schedule_at' => env('GUARDLMS_SCHEDULE_AT', '03:17'),
    // Generate this separately during deployment; no Composer process runs in web requests.
    'outdated_file' => env('GUARDLMS_OUTDATED_FILE'),
    // CLI cannot determine the HTTP server. Supply an actual signature if known.
    'webserver' => env('GUARDLMS_WEBSERVER'),
];
