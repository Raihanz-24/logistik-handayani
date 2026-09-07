<?php

namespace App\Services\Pdf;

use RuntimeException;

class BelanjaPdfDocument
{
    private const PAGE_WIDTH = 841.89;

    private const PAGE_HEIGHT = 595.28;

    private const ROWS_PER_PAGE = 19;

    /** @var array<int, array{key: string, label: string, width: float, align?: string}> */
    private const COLUMNS = [
        ['key' => 'sequence', 'label' => 'No.', 'width' => 25, 'align' => 'right'],
        ['key' => 'date', 'label' => 'Tanggal', 'width' => 55],
        ['key' => 'session', 'label' => 'Sesi', 'width' => 88],
        ['key' => 'supplier', 'label' => 'Toko / Supplier', 'width' => 88],
        ['key' => 'item', 'label' => 'Barang', 'width' => 142],
        ['key' => 'quantity', 'label' => 'Jumlah', 'width' => 55, 'align' => 'right'],
        ['key' => 'price', 'label' => 'Harga', 'width' => 73, 'align' => 'right'],
        ['key' => 'subtotal', 'label' => 'Subtotal', 'width' => 80, 'align' => 'right'],
        ['key' => 'note', 'label' => 'Keterangan', 'width' => 125],
        ['key' => 'evidence', 'label' => 'Bukti', 'width' => 66],
    ];

    /**
     * @param  array<int, array<string, int|string>>  $rows
     * @param  array<string, int|string>  $context
     */
    public function render(array $rows, array $context = []): string
    {
        $pages = array_chunk($rows, self::ROWS_PER_PAGE) ?: [[]];
        $streams = [];

        foreach ($pages as $index => $pageRows) {
            $streams[] = $this->renderPage($pageRows, $context, $index + 1, count($pages));
        }

        return $this->assemble($streams);
    }

    /** @param array<int, array<string, int|string>> $rows @param array<string, int|string> $context */
    private function renderPage(array $rows, array $context, int $page, int $pageCount): string
    {
        $content = '';
        $this->rectangle($content, 22, 20, 797, 73, '#111827');
        $this->rectangle($content, 22, 20, 7, 73, '#F59E0B');
        $this->text($content, 42, 41, 'LOGISTIK TAMAN AIR HANDAYANI PAITON', 15, true, '#FFFFFF');
        $this->text($content, 42, 59, 'Laporan detail kalkulator belanja', 8.5, false, '#CBD5E1');
        $this->text($content, 42, 76, 'Dibuat '.$this->clean((string) ($context['generated_at'] ?? '')).' WIB', 7.2, false, '#94A3B8');
        $this->text($content, 805, 43, 'LAPORAN BELANJA', 9, true, '#FBBF24', 'right');
        $this->text($content, 805, 64, $this->fit((string) ($context['period'] ?? 'Semua periode'), 190, 7.5), 7.5, true, '#E2E8F0', 'right');

        $this->summary($content, 22, 103, 188, 'TOTAL PENGELUARAN', self::rupiah((int) ($context['total_out'] ?? 0)));
        $this->summary($content, 220, 103, 165, 'TRANSAKSI TOKO', number_format((int) ($context['transaction_count'] ?? 0), 0, ',', '.'));
        $this->summary($content, 395, 103, 155, 'SESI BELANJA', number_format((int) ($context['session_count'] ?? 0), 0, ',', '.'));
        $this->summary($content, 560, 103, 259, 'FILTER', $this->fit((string) ($context['period'] ?? ''), 240, 9));

        $search = trim((string) ($context['search'] ?? ''));
        $this->text($content, 23, 150, $search === '' ? 'Semua detail barang ditampilkan. Foto nota tidak disisipkan ke PDF.' : 'Pencarian: "'.$this->fit($search, 600, 7.2).'"', 7.2, false, '#475569');

        $top = 160;
        $headerHeight = 23;
        $rowHeight = 19;
        $x = 22;

        foreach (self::COLUMNS as $column) {
            $this->rectangle($content, $x, $top, $column['width'], $headerHeight, '#1E3A5F');
            $align = $column['align'] ?? 'left';
            $textX = $align === 'right' ? $x + $column['width'] - 4 : $x + 4;
            $this->text($content, $textX, $top + 15, $column['label'], 6.5, true, '#FFFFFF', $align);
            $x += $column['width'];
        }

        if ($rows === []) {
            $this->rectangle($content, 22, $top + $headerHeight, 797, 48, '#F8FAFC');
            $this->text($content, 420, $top + 52, 'Tidak ada transaksi sesuai filter.', 9, true, '#64748B', 'center');
        }

        foreach ($rows as $rowIndex => $row) {
            $rowTop = $top + $headerHeight + ($rowIndex * $rowHeight);
            $this->rectangle($content, 22, $rowTop, 797, $rowHeight, $rowIndex % 2 ? '#F8FAFC' : '#FFFFFF');
            $this->line($content, 22, $rowTop + $rowHeight, 819, $rowTop + $rowHeight, '#E2E8F0', .35);
            $x = 22;

            foreach (self::COLUMNS as $column) {
                $value = (string) ($row[$column['key']] ?? '-');
                $align = $column['align'] ?? 'left';
                $textX = $align === 'right' ? $x + $column['width'] - 4 : $x + 4;
                $this->text(
                    $content,
                    $textX,
                    $rowTop + 13,
                    $this->fit($value, $column['width'] - 8, 6.35),
                    6.35,
                    in_array($column['key'], ['item', 'subtotal'], true),
                    $column['key'] === 'subtotal' ? '#B91C1C' : '#334155',
                    $align,
                );
                $x += $column['width'];
            }
        }

        $this->line($content, 22, 570, 819, 570, '#CBD5E1', .45);
        $this->text($content, 22, 584, 'Laporan hanya membaca data; tidak mengubah stok, mutasi, transaksi, atau foto.', 6.8, false, '#64748B');
        $this->text($content, 819, 584, "Halaman {$page} dari {$pageCount}", 6.8, true, '#475569', 'right');

        return $content;
    }

    private function summary(string &$content, float $x, float $top, float $width, string $label, string $value): void
    {
        $this->rectangle($content, $x, $top, $width, 35, '#F8FAFC', '#CBD5E1');
        $this->text($content, $x + 9, $top + 13, $label, 6.2, true, '#64748B');
        $this->text($content, $x + 9, $top + 28, $value, 9.2, true, '#0F172A');
    }

    /** @param array<int, string> $streams */
    private function assemble(array $streams): string
    {
        $objects = [
            1 => '', 2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pageIds = [];

        foreach ($streams as $stream) {
            $contentId = count($objects) + 1;
            $objects[$contentId] = '<< /Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
            $pageId = count($objects) + 1;
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $contentId);
            $pageIds[] = $pageId;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id): string => "{$id} 0 R", $pageIds)).'] /Count '.count($pageIds).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        for ($id = 1; $id <= count($objects); $id++) {
            if (! isset($offsets[$id])) {
                throw new RuntimeException('Struktur PDF laporan belanja tidak valid.');
            }
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function rectangle(string &$content, float $x, float $top, float $width, float $height, string $fill, ?string $stroke = null): void
    {
        $y = self::PAGE_HEIGHT - $top - $height;
        [$r, $g, $b] = $this->rgb($fill);
        $strokeCommand = '';
        $operator = 'f';

        if ($stroke) {
            [$sr, $sg, $sb] = $this->rgb($stroke);
            $strokeCommand = sprintf(' %.3F %.3F %.3F RG', $sr, $sg, $sb);
            $operator = 'B';
        }

        $content .= sprintf("q %.3F %.3F %.3F rg%s %.2F %.2F %.2F %.2F re %s Q\n", $r, $g, $b, $strokeCommand, $x, $y, $width, $height, $operator);
    }

    private function line(string &$content, float $x1, float $top1, float $x2, float $top2, string $color, float $width): void
    {
        [$r, $g, $b] = $this->rgb($color);
        $content .= sprintf("q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n", $r, $g, $b, $width, $x1, self::PAGE_HEIGHT - $top1, $x2, self::PAGE_HEIGHT - $top2);
    }

    private function text(string &$content, float $x, float $top, string $text, float $size, bool $bold, string $color, string $align = 'left'): void
    {
        $encoded = $this->encode($text);
        $estimatedWidth = strlen($encoded) * $size * .49;
        $x = $align === 'right' ? $x - $estimatedWidth : ($align === 'center' ? $x - ($estimatedWidth / 2) : $x);
        [$r, $g, $b] = $this->rgb($color);
        $content .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $r, $g, $b, $x, self::PAGE_HEIGHT - $top, $this->escape($encoded));
    }

    private function fit(string $text, float $width, float $size): string
    {
        $text = $this->clean($text);
        $encoded = $this->encode($text);
        $max = max(1, (int) floor($width / ($size * .49)));

        if (strlen($encoded) <= $max) {
            return $text;
        }

        return mb_convert_encoding(substr($encoded, 0, max(1, $max - 3)).'...', 'UTF-8', 'Windows-1252');
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function encode(string $text): string
    {
        return iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $this->clean($text)) ?: '';
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text);
    }

    /** @return array{float, float, float} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
