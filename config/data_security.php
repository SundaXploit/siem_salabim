<?php

return [
    // Keep this independent key stable when rotating APP_KEY. An existing
    // installation can initially derive it from APP_KEY, then persist it.
    'blind_index_key' => env('SIEM_BLIND_INDEX_KEY'),
    'allow_legacy_plaintext' => env('SIEM_ALLOW_LEGACY_PLAINTEXT', false),
    'search_max_tokens' => max(0, min(512, (int) env('SIEM_SEARCH_MAX_TOKENS', 512))),
    'search_max_bytes' => max(0, min(131072, (int) env('SIEM_SEARCH_MAX_BYTES', 131072))),
];
