<?php

namespace App\Services\Ai;

/**
 * Merapikan balasan AI menjadi teks biasa yang bersih untuk ditampilkan.
 *
 * Model bahasa sering mengembalikan format Markdown (mis. "## Judul",
 * "**tebal**", "* poin"). Di antarmuka chat kita menampilkan teks polos, jadi
 * simbol seperti '#' dan '*' tampak mengganggu. Kelas ini menghapus penanda
 * Markdown tanpa mengubah isi kalimat.
 */
class AiTextFormatter
{
    public static function toPlain(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Buang pagar blok kode.
        $text = preg_replace('/```[a-zA-Z0-9_-]*\s*\n?/', '', $text) ?? $text;
        $text = str_replace('```', '', $text);

        $lines = explode("\n", $text);
        $out = [];

        foreach ($lines as $line) {
            // Judul: buang tanda '#' di awal baris.
            $line = preg_replace('/^\s{0,3}#{1,6}\s*/', '', $line) ?? $line;

            // Kutipan: buang '>' di awal.
            $line = preg_replace('/^\s{0,3}>\s?/', '', $line) ?? $line;

            // Garis pemisah horizontal -> baris kosong.
            if (preg_match('/^\s*([-*_])\1{2,}\s*$/', $line)) {
                $out[] = '';

                continue;
            }

            // Daftar berpoin: ubah "- ", "* ", "+ " menjadi "• ".
            $line = preg_replace('/^(\s*)[-*+]\s+/', '$1• ', $line) ?? $line;

            // Tebal/miring: **x**, __x__, *x*, _x_.
            $line = preg_replace('/\*\*(.+?)\*\*/', '$1', $line) ?? $line;
            $line = preg_replace('/__(.+?)__/', '$1', $line) ?? $line;
            $line = preg_replace('/\*(.+?)\*/', '$1', $line) ?? $line;
            $line = preg_replace('/_(.+?)_/', '$1', $line) ?? $line;

            // Kode inline: `x`.
            $line = preg_replace('/`([^`]+)`/', '$1', $line) ?? $line;

            // Sisa simbol yang mengganggu.
            $line = str_replace(['**', '*', '`', '#'], '', $line);

            $out[] = rtrim($line);
        }

        $text = implode("\n", $out);

        // Rapatkan baris kosong berlebih.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
