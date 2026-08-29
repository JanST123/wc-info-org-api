<?php

return [
    's3' => [
        'endpoint' => env('S3_ENDPOINT', 'https://gos3.io'),
        'key' => env('S3_KEY'),
        'secret' => env('S3_SECRET'),
        'bucket' => env('S3_BUCKET', 'wcinfo'),
        'public_url' => rtrim(env('S3_PUBLIC_URL', 'https://wcinfo.eu-central-1.gos3.io/'), '/') . '/',
        'region' => env('S3_REGION', 'eu'),
        'use_path_style_endpoint' => true,
    ],

    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
    ],

    'smtp' => [
        'host' => env('SMTP_HOST', 'smtp.example.com'),
        'port' => (int) env('SMTP_PORT', 465),
        'user' => env('SMTP_USER'),
        'password' => env('SMTP_PASSWORD'),
        'encryption' => env('SMTP_ENCRYPTION', 'ssl'),
    ],

    'sender_mail' => env('SENDER_MAIL', 'noreply@wc-info.de'),

    'admin_hash_secret' => env('ADMIN_HASH_SECRET', ''), // prefix salt used for qualify/delete links
    'admin_hash_secret2' => env('ADMIN_HASH_SECRET2', ''), // suffix salt

    'discover' => [
        'limit' => (int) env('DISCOVER_PLACES_LIMIT', 500),
        'radius' => (int) env('DISCOVER_PLACES_RADIUS', 2000),
        'cache_days' => (int) env('DISCOVER_PLACES_CACHE_DAYS', 30),
    ],
];
