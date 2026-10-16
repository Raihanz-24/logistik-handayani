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
    | Driver & Profil Model (lokal vs cloud)
    |--------------------------------------------------------------------------
    | AI_DRIVER menentukan model mana yang dipakai:
    |   cloud  → HANYA profil cloud (perilaku lama; default, aman).
    |   local  → HANYA profil lokal (Xcode AI sendiri, tanpa API key cloud).
    |   auto   → coba LOKAL dulu; bila gagal/tak terpasang, otomatis ke cloud.
    |
    | Profil 'cloud' memakai kunci legacy (AI_BASE_URL/AI_API_KEY/AI_MODEL).
    | Profil 'local' menunjuk ke server model OpenAI-compatible di mesin sendiri
    | (Ollama/vLLM/LM Studio/llama.cpp). Semua additive & default aman.
    */
    'driver' => env('AI_DRIVER', 'cloud'),

    'profiles' => [
        'local' => [
            'label' => (string) env('AI_LOCAL_LABEL', 'Xcode AI (Lokal)'),
            'base_url' => rtrim((string) env('AI_LOCAL_BASE_URL', 'http://127.0.0.1:11434/v1'), '/'),
            'api_key' => (string) env('AI_LOCAL_API_KEY', 'local'),
            'model' => (string) env('AI_LOCAL_MODEL', 'qwen2.5:14b-instruct'),
            'chat_path' => (string) env('AI_LOCAL_CHAT_PATH', '/chat/completions'),
            'models_path' => (string) env('AI_LOCAL_MODELS_PATH', '/models'),
            // Utamakan ini saat AI_DRIVER=auto. Biarkan true; ubah bila perlu.
            'primary' => (bool) env('AI_LOCAL_PRIMARY', true),
        ],
        'cloud' => [
            'label' => (string) env('AI_CLOUD_LABEL', 'Cloud (KryptonLab)'),
            // Diisi dinamis dari kunci legacy (AI_BASE_URL dst) saat runtime.
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Distilasi (belajar mandiri dari model cloud)
    |--------------------------------------------------------------------------
    | Saat model cloud yang menjawab, kita simpan pasangan (perintah -> aksi ->
    | jawaban) ke tabel ai_training_examples. Data ini bisa diekspor (JSONL)
    | untuk fine-tune model lokal. Tidak mengubah perilaku generasi.
    */
    'training' => [
        'capture' => (bool) env('AI_TRAINING_CAPTURE', true),
        // Simpan hanya giliran yang memakai tool (lebih bernilai & hemat baris).
        'only_with_tools' => (bool) env('AI_TRAINING_ONLY_WITH_TOOLS', true),
        // Saat menjawab pakai model lokal, apakah data latih boleh disimpan?
        // Default false agar server tidak dipenuhi data; bisa diaktifkan dari UI.
        'local_enabled' => (bool) env('AI_TRAINING_LOCAL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pengaturan provider dari UI (database)
    |--------------------------------------------------------------------------
    | Bila true, nilai profil "lokal" (base_url/api_key/model) & izin merekam
    | data latih diambil dari tabel ai_settings sehingga bisa diubah via UI tanpa
    | menyentuh .env. Nilai kosong tetap jatuh ke config/ai.php.
    */
    'provider_settings_from_db' => (bool) env('AI_PROVIDER_SETTINGS_FROM_DB', true),

    /*
    |--------------------------------------------------------------------------
    | Kecerdasan lokal (deterministik, tanpa LLM)
    |--------------------------------------------------------------------------
    | Jalur cepat untuk perintah belanja yang JELAS & terstruktur: resolusi
    | supplier + barang dilakukan deterministik, draft disiapkan TANPA memanggil
    | API model. Hemat token & lebih akurat (tak mengarang). Bila ragu sedikit
    | pun, otomatis diteruskan ke model API. Default ON dan aman.
    */
    'local_intelligence' => [
        'enabled' => (bool) env('AI_LOCAL_INTELLIGENCE', true),
    ],

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
    'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),

    /*
    |--------------------------------------------------------------------------
    | Batas loop tool-calling
    |--------------------------------------------------------------------------
    | Maksimum berapa kali AI boleh memanggil tool dalam satu balasan sebelum
    | dipaksa berhenti. Mencegah loop tak berujung.
    */
    'max_tool_iterations' => (int) env('AI_MAX_TOOL_ITERATIONS', 10),

    /*
    |--------------------------------------------------------------------------
    | Batas riwayat yang dikirim ke model
    |--------------------------------------------------------------------------
    | Jumlah pesan terakhir yang disertakan sebagai konteks percakapan.
    */
    'history_limit' => (int) env('AI_HISTORY_LIMIT', 80),

    /*
    |--------------------------------------------------------------------------
    | Anggaran token harian
    |--------------------------------------------------------------------------
    | Dipakai halaman pemakaian untuk memantau & memperingatkan bila pemakaian
    | mendekati/melampaui batas. Bukan hard-stop (tidak memblokir permintaan),
    | hanya indikator agar terkendali.
    */
    'budget' => [
        'daily_tokens' => (int) env('AI_BUDGET_DAILY_TOKENS', 1000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pembelajaran afinitas supplier <-> barang
    |--------------------------------------------------------------------------
    | Asisten "belajar" dari transaksi belanja yang sudah ada: barang apa yang
    | biasanya diorder dari tiap supplier (mis. Bu Elly / Bu Hen). Bobot dipakai
    | untuk menghitung skor afinitas (frekuensi + kebaruan + kuantitas).
    */
    'affinity' => [
        'weight_frequency' => (float) env('AI_AFFINITY_WEIGHT_FREQUENCY', 1.0),
        'weight_recency' => (float) env('AI_AFFINITY_WEIGHT_RECENCY', 3.0),
        'weight_quantity' => (float) env('AI_AFFINITY_WEIGHT_QUANTITY', 0.5),
        'weight_order' => (float) env('AI_AFFINITY_WEIGHT_ORDER', 2.0),
        'frequency_cap' => (int) env('AI_AFFINITY_FREQUENCY_CAP', 10),
        'recency_days' => (int) env('AI_AFFINITY_RECENCY_DAYS', 60),
        'suggestion_limit' => (int) env('AI_AFFINITY_SUGGESTION_LIMIT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Penghematan konteks (agar hemat token & ingatan lebih longgar)
    |--------------------------------------------------------------------------
    | knowledge_limit   : jumlah entri pengetahuan relevan yang disuntik.
    | older_summary_lines: jumlah baris ringkasan percakapan lama.
    | draft_item_cap    : item maksimum yang dirinci di prompt draft berjalan.
    */
    'context' => [
        'knowledge_limit' => (int) env('AI_CONTEXT_KNOWLEDGE_LIMIT', 30),
        'older_summary_lines' => (int) env('AI_CONTEXT_OLDER_SUMMARY_LINES', 12),
        'draft_item_cap' => (int) env('AI_CONTEXT_DRAFT_ITEM_CAP', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Penanda varian barang
    |--------------------------------------------------------------------------
    | Kata yang membedakan barang "serupa" (mis. "tempe" vs "tempe karyawan").
    | Aturan: bila user TIDAK menyebut penanda, jangan sentuh barang ber-penanda;
    | bila user MENYEBUT penanda, jangan sentuh barang tanpa penanda. Daftar ini
    | juga diperkaya otomatis dari nama master (lihat BarangVariantResolver).
    */
    'variant_markers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AI_VARIANT_MARKERS', 'karyawan')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Pencocokan & keputusan (agar AI tidak ragu / tidak salah pilih)
    |--------------------------------------------------------------------------
    | search.* : bonus skor saat menentukan kandidat terbaik.
    | price.*  : toleransi perbedaan harga dianggap wajar (fluktuasi normal).
    */
    'search' => [
        'affinity_bonus' => (int) env('AI_SEARCH_AFFINITY_BONUS', 300),
        'memory_bonus' => (int) env('AI_SEARCH_MEMORY_BONUS', 200),
        'variant_bonus' => (int) env('AI_SEARCH_VARIANT_BONUS', 150),
    ],

    'price' => [
        // Perbedaan <= persen ini dari harga terakhir dianggap wajar (bukan
        // kenaikan/penurunan yang perlu ditanyakan).
        'tolerance_percent' => (float) env('AI_PRICE_TOLERANCE_PERCENT', 15),
    ],

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
        'saran_belanja_supplier' => 'view_any_supplier',
        'riwayat_transaksi_belanja' => 'view_any_kalkulator_belanja',
        'buat_draft_mutasi' => 'create_mutasi',
        'buat_sesi_belanja' => 'create_kalkulator_belanja',
        'catat_transaksi_belanja' => 'update_kalkulator_belanja',
        'tambah_item_belanja' => 'update_kalkulator_belanja',
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

        // Mikrofon TETAP hidup selama sesi (tidak diputus saat Anda berjeda).
        // Bila false, mesin berhenti tiap kali Anda diam sesaat — inilah yang
        // membuat AWALAN ucapan berikutnya sering terpotong. Default true.
        'continuous' => (bool) env('AI_VOICE_CONTINUOUS', true),

        // Jeda (milidetik) sebelum mesin dinyalakan ulang BILA browser tetap
        // memutusnya (mis. Chrome Android). Kecil = awalan tidak terpotong.
        'restart_delay_ms' => (int) env('AI_VOICE_RESTART_DELAY_MS', 0),

        // Sisipkan tanda titik + spasi otomatis di setiap jeda/frasa selesai,
        // sehingga hasil suara tidak menempel ("halo. apa kabar."). Default true.
        'auto_punctuation' => (bool) env('AI_VOICE_AUTO_PUNCTUATION', true),

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
