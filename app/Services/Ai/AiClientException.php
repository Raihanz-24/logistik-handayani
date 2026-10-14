<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Kesalahan klien AI yang aman ditampilkan ke user
 * (tanpa membocorkan body/credential).
 */
class AiClientException extends RuntimeException
{
}
