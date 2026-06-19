<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bobot Kriteria SAW
    |--------------------------------------------------------------------------
    |
    | Bobot akan dinormalisasi kembali oleh service sehingga jumlah akhirnya
    | selalu 1. Nilai berikut memberikan kepentingan yang sama untuk setiap
    | kriteria dan dapat disesuaikan dengan hasil penelitian.
    |
    */
    'weights' => [
        'frekuensi_pemakaian' => 1,
        'jumlah_pemakaian' => 1,
        'sisa_stok' => 1,
    ],

    'limit' => 5,
    'period_days' => 30,
];
