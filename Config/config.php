<?php

return [
    'name' => 'Everymarket',
    'shop_domain' => env('EM_SHOP_DOMAIN', ''),
    'api_domain' => env('EM_API_DOMAIN', ''),
    'access_token' => env('EM_ACCESS_TOKEN', ''),
    'api_version' => env('EM_API_VERSION', 'v1'),
    'stats_api_token' => env('EM_STATS_API_TOKEN', ''),
    // Write token for POST /everymarket/api/conversations/{id}/reply. Kept
    // separate from stats_api_token, which clients send in the query string.
    'reply_api_token' => env('EM_REPLY_API_TOKEN', ''),
    // FreeScout user that seller replies from EM Ops are sent as.
    'seller_reply_user_id' => env('EM_SELLER_REPLY_USER_ID', ''),
    'bearer_token' => env('EM_BEARER_TOKEN', ''),
];
