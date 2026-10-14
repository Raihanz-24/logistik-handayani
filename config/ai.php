<?php

/**
 * Konfigurasi Asisten AI (chat & voice) untuk Logistik Handayani.
 *
 * Kredensial (api_key) diambil dari .env — JANGAN di-hardcode di repo.
 * Default OFF sehingga kode ini aman di-merge: tidak ada perubahan perilaku
 * sampai AI_ENABLED=true dan AI_API_KEY diisi.
 *
 * Catatan: gateway KryptonLab (https://api.kryptonlab.id/v1) sudah TERVERIFIKASI
 * OpenAI-compatible: GET /models dan POST /chat/completions berhasil, termasuk
 * tool-calling dan field `usage`. Sesuaikan base_url / model / paths di .env bila
 * kredensial atau model berubah.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Aktifkan Asisten AI
    |--------------------------------------------------------------------------
    | Default OFF. Bila OFF, halaman Asisten disembunyikan dan semua panggilan
    | ke AI dibatalkan (fail-safe). Aktifkan per-environment via AI_ENABLED.
    */
    'enabled' => (bool) env('AI_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider & Kredensial
    |--------------------------------------------------------------------------
    */
    'provider' => env('AI_PROVIDER', 'kryptonlab'),
    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.kryptonlab.id/v1'), '/'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL', 'deepseek-v4.1-flash'),

    /*
    |--------------------------------------------------------------------------
    | Path endpoint (relatif terhadap base_url)
    |--------------------------------------------------------------------------
    | Dipisah agar mudah disesuaikan bila format gateway berbeda dari OpenAI.
    */
    'chat_path' => env('AI_CHAT_PATH', '/chat/completions'),
    'models_path' => env('AI_MODELS_PATH', '/models'),

    /*
    |--------------------------------------------------------------------------
    | Parameter HTTP
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('AI_TIMEOUT', 60),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
    'retry_times' => (int) env('AI_RETRY_TIMES', 2),
    'retry_sleep_ms' => (int) env('AI_RETRY_SLEEP_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Parameter generasi
    |--------------------------------------------------------------------------
    */
    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),

    /*
    |--------------------------------------------------------------------------
    | Batas loop tool-calling
    |--------------------------------------------------------------------------
    | Maksimum berapa kali AI boleh memanggil tool dalam satu balasan sebelum
    | dipaksa berhenti. Mencegah loop tak berujung.
    */
    'max_tool_iterations' => (int) env('AI_MAX_TOOL_ITERATIONS', 6),

    /*
    |--------------------------------------------------------------------------
    | Batas riwayat yang dikirim ke model
    |--------------------------------------------------------------------------
    | Jumlah pesan terakhir yang disertakan sebagai konteks percakapan.
    */
    'history_limit' => (int) env('AI_HISTORY_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | Permission per-tool (Shield)
    |--------------------------------------------------------------------------
    | Setiap tool dicek terhadap permission user yang login. Bila user tidak
    | punya permission tersebut, tool ditolak. Gunakan nama permission yang
    | sudah ada di RolePermissionCatalog. Tool read-only memakai permission
    | view_any_*; tool tulis memakai permission create_*.
    */
    'permissions' => [
        'cari_barang' => 'view_any_barang',
        'cek_stok' => 'view_any_barang::lokasi',
        'cari_lokasi' => 'view_any_lokasi',
        'cari_rak' => 'view_any_lokasi',
        'cari_supplier' => 'view_any_supplier',
        'buat_draft_mutasi' => 'create_mutasi',
        'buat_sesi_belanja' => 'create_kalkulator_belanja',
        'catat_transaksi_belanja' => 'update_kalkulator_belanja',
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice (Web Speech API di browser)
    |--------------------------------------------------------------------------
    | Hanya konfigurasi lokasi & kecepatan bicara; pemrosesan ada di sisi klien.
    */
    'voice' => [
        'lang' => env('AI_VOICE_LANG', 'id-ID'),
        'speech_rate' => (float) env('AI_VOICE_RATE', 1.0),

        // Baca ulang balasan/ringkasan lewat Text-to-Speech (hands-free).
        // Default OFF agar tidak mengganggu; bisa diaktifkan user lewat toggle.
        'auto_speak' => (bool) env('AI_VOICE_AUTO_SPEAK', false),

        // Baca ulang otomatis ringkasan draft yang menunggu konfirmasi.
        'read_summary' => (bool) env('AI_VOICE_READ_SUMMARY', true),

        // Nama voice TTS yang diprioritaskan (cocok sebagian, huruf kecil).
        // Contoh: "google indonesian", "andika", "gadis", "damayanti".
        // Bila kosong, sistem otomatis memilih voice Bahasa Indonesia terbaik.
        'voice_names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AI_VOICE_NAMES', 'google indonesian,andika,gadis,damayanti,indonesian')),
        ))),

        // Kata perintah suara (dinormalkan ke huruf kecil, tanpa tanda baca).
        'commands' => [
            'confirm' => ['ya', 'iya', 'betul', 'benar', 'simpan', 'konfirmasi', 'lanjut', 'ok', 'oke', 'setuju'],
            'cancel' => ['batal', 'batalkan', 'salah', 'gagal', 'urungkan', 'jangan simpan'],
        ],
    ],

];
