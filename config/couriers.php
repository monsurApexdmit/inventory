<?php

/*
 * Courier API integration (Pathao, Steadfast). Values marked UNCONFIRMED come from the open-source
 * client packages, not from an official spec — see COURIER_API_CONTRACT.md "Assumptions".
 */
return [
    'timeout' => (int) env('COURIER_HTTP_TIMEOUT', 15),
    'connect_timeout' => (int) env('COURIER_HTTP_CONNECT_TIMEOUT', 10),

    // Scheduled sync (couriers:sync)
    'sync' => [
        'stale_minutes' => (int) env('COURIER_SYNC_STALE_MINUTES', 10),
        'batch' => (int) env('COURIER_SYNC_BATCH', 100),
    ],

    // Public base URL used to build webhook callback URLs (falls back to app.url)
    'webhook_base_url' => env('COURIER_WEBHOOK_BASE_URL'),

    'steadfast' => [
        'base_url' => env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'),
        // UNCONFIRMED public tracking page
        'tracking_url' => env('STEADFAST_TRACKING_URL', 'https://steadfast.com.bd/t/{code}'),
        'bulk_max' => 500,
    ],

    'pathao' => [
        'base_url' => [
            'live' => env('PATHAO_BASE_URL', 'https://api-hermes.pathao.com'),
            'sandbox' => env('PATHAO_SANDBOX_BASE_URL', 'https://courier-api-sandbox.pathao.com'),
        ],
        // UNCONFIRMED public tracking page
        'tracking_url' => env('PATHAO_TRACKING_URL', 'https://merchant.pathao.com/tracking?consignment_id={code}&phone={phone}'),
        // UNCONFIRMED: Pathao's webhook-integration handshake expects this response header value (set it from the Pathao merchant panel docs)
        'webhook_integration_secret' => env('PATHAO_WEBHOOK_INTEGRATION_SECRET'),
        'locations_cache_hours' => 24,
    ],
];
