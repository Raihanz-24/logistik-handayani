<?php

/**
 * Konfigurasi SSO Logistik ↔ Handayani Portal (Opsi B).
 *
 * Kredensial (client_id/client_secret) diambil dari .env — JANGAN
 * di-hardcode di repo. Portal & Logistik punya DB terpisah.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Aktifkan SSO
    |--------------------------------------------------------------------------
    | Default OFF. Aktifkan per-environment (staging/produksi) via SSO_ENABLED.
    | Bila OFF, route /sso/* tidak berfungsi (login langsung tetap jalan).
    */
    'enabled' => (bool) env('SSO_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Base URL Portal (Authorization Server)
    |--------------------------------------------------------------------------
    | Contoh: https://portal.handayani.my.id
    */
    'portal_base_url' => rtrim((string) env('SSO_PORTAL_BASE_URL', 'https://portal.handayani.my.id'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Kredensial OAuth client (dari Portal)
    |--------------------------------------------------------------------------
    */
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),

    'redirect_uri' => env('SSO_REDIRECT_URI', 'https://handayani.my.id/sso/callback'),

    /*
    |--------------------------------------------------------------------------
    | Path endpoint Portal
    |--------------------------------------------------------------------------
    */
    'authorize_path' => '/oauth/authorize',
    'token_path' => '/oauth/token',

    /*
    |--------------------------------------------------------------------------
    | TTL state & verifier di session (detik)
    |--------------------------------------------------------------------------
    | State/verifier disimpan di session Logistik, sekali pakai.
    */
    'state_ttl' => (int) env('SSO_STATE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Timeout HTTP ke Portal (detik)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('SSO_HTTP_TIMEOUT', 10),
];
