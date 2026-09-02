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

    // after_response cocok untuk shared hosting; queue cocok bila worker selalu aktif.
    'processing_mode' => env('FOTO_BARANG_PROCESSING_MODE', 'after_response'),
    'processing_queue' => env('FOTO_BARANG_PROCESSING_QUEUE', 'default'),
];
