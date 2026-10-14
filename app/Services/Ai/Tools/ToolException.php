<?php

namespace App\Services\Ai\Tools;

use RuntimeException;

/**
 * Kesalahan yang aman ditampilkan kembali ke model/user (tanpa stack trace).
 */
class ToolException extends RuntimeException
{
}
