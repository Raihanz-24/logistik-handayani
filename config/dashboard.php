<?php

return [
    'weather' => [
        'location' => 'Paiton',
        'latitude' => (float) env('WEATHER_LATITUDE', -7.71493),
        'longitude' => (float) env('WEATHER_LONGITUDE', 113.51505),
        'timezone' => 'Asia/Jakarta',
        'cache_minutes' => 20,
    ],
];
