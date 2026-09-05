<?php

return [
    's3' => [
        'endpoint' => env('S3_ENDPOINT', 'https://gos3.io'),
        'key' => env('S3_KEY'),
        'secret' => env('S3_SECRET'),
        'bucket' => env('S3_BUCKET', 'wcinfo'),
        'public_url' => rtrim(env('S3_PUBLIC_URL', 'https://wcinfo.eu-central-1.gos3.io/'), '/').'/',
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

    'admin' => [
        'user' => env('ADMIN_USER', 'admin'),
        'password' => env('ADMIN_PASSWORD', 'secret'),
    ],

    'admin_hash_secret' => env('ADMIN_HASH_SECRET', ''), // prefix salt used for qualify/delete links
    'admin_hash_secret2' => env('ADMIN_HASH_SECRET2', ''), // suffix salt

    'discover' => [
        'limit' => (int) env('DISCOVER_PLACES_LIMIT', 500),
        'radius' => (int) env('DISCOVER_PLACES_RADIUS', 2000),
        'cache_days' => (int) env('DISCOVER_PLACES_CACHE_DAYS', 30),
    ],

    'public_accessible_types' => [
        // Transit & Transport
        'train_station',
        'subway_station',
        'light_rail_station',
        'transit_station',
        'transit_depot',
        'bus_station',
        'airport',
        'rest_stop',
        'taxi_stand',

        // Parks, Squares & Nature
        'park',
        'city_park',
        'town_square',
        'natural_feature',
        'campground',
        'rv_park',

        // Civic & Government
        'city_hall',
        'local_government_office',
        'government_office',
        'courthouse',
        'library',
        'post_office',
        'cemetery',

        // Culture & Tourism
        'tourist_attraction',
        'museum',
        'art_museum',
        'planetarium',
        'aquarium',
        'zoo',
        'amusement_park',

        // Large Public Facilities
        'shopping_mall',
        'department_store',
        'stadium',
        'swimming_pool',
        'sports_activity_location',
        'gas_station',
        'parking',
        'parking_lot',
    ],
];
