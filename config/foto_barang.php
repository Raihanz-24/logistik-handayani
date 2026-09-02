<?php

return [
    'default_location_name' => env(
        'FOTO_BARANG_LOCATION_NAME',
        'Kecamatan Paiton, Jawa Timur, Indonesia',
    ),

    'default_address' => env(
        'FOTO_BARANG_ADDRESS',
        'Jl. Raya Paiton No. km.137, Dusun Matikan, Sumberejo, Kec. Paiton, Kabupaten Probolinggo, Jawa Timur 67291, Indonesia',
    ),

    'default_latitude' => (float) env('FOTO_BARANG_LATITUDE', -7.717710),
    'default_longitude' => (float) env('FOTO_BARANG_LONGITUDE', 113.537297),

    // Batas foto mentah. Hasil akhir diperkecil dan dikompres ulang sebagai JPEG.
    'max_upload_kb' => 10 * 1024,
    'max_dimension' => 1920,
    'target_file_size' => 1400 * 1024,
    // Crop ringan atas-bawah agar watermark terbaca pada thumbnail WhatsApp.
    'vertical_crop_ratio' => 0.045,

    // after_response cocok untuk shared hosting; queue cocok bila worker selalu aktif.
    'processing_mode' => env('FOTO_BARANG_PROCESSING_MODE', 'after_response'),
    'processing_queue' => env('FOTO_BARANG_PROCESSING_QUEUE', 'default'),

    // Satu permintaan per lokasi lalu disimpan di cache agar pemotretan berikutnya tetap cepat.
    'reverse_geocoding' => [
        'enabled' => env('FOTO_BARANG_REVERSE_GEOCODING', true),
        'url' => env('FOTO_BARANG_GEOCODER_URL', 'https://nominatim.openstreetmap.org/reverse'),
        'timeout' => (int) env('FOTO_BARANG_GEOCODER_TIMEOUT', 5),
        'cache_days' => (int) env('FOTO_BARANG_GEOCODER_CACHE_DAYS', 30),
        'user_agent' => env('FOTO_BARANG_GEOCODER_USER_AGENT'),
    ],
];
