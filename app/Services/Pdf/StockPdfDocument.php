<?php

namespace App\Services\Pdf;

use RuntimeException;

class StockPdfDocument
{
    private const PAGE_WIDTH = 841.89;

    private const PAGE_HEIGHT = 595.28;

    private const ROWS_PER_PAGE = 19;

    /** @var array<int, array{key: string, label: string, width: float, align?: string}> */
    private const COLUMNS = [
        ['key' => 'sequence', 'label' => 'No.', 'width' => 27, 'align' => 'right'],
        ['key' => 'kode_barang', 'label' => 'Kode', 'width' => 75],
        ['key' => 'nama_barang', 'label' => 'Nama Barang', 'width' => 205],
        ['key' => 'gudang', 'label' => 'Gudang', 'width' => 155],
        ['key' => 'rak', 'label' => 'Rak', 'width' => 60],
        ['key' => 'stok_baik', 'label' => 'Baik', 'width' => 54, 'align' => 'right'],
        ['key' => 'stok_rusak', 'label' => 'Rusak', 'width' => 54, 'align' => 'right'],
        ['key' => 'stok_hilang', 'label' => 'Hilang', 'width' => 54, 'align' => 'right'],
        ['key' => 'stok', 'label' => 'Total Stok', 'width' => 54, 'align' => 'right'],
        ['key' => 'satuan', 'label' => 'Satuan', 'width' => 59],
    ];

    /**
     * @param  array<int, array<string, int|string>>  $rows
     * @param  array{filter_description?: string, search?: string, total_items?: int, total_stock?: int, generated_at?: string}  $context
     */
    public function render(array $rows, array $context = []): string
    {
        $pages = array_chunk($rows, self::ROWS_PER_PAGE);

        if ($pages === []) {
            $pages = [[]];
        }

        $pageStreams = [];
        $pageCount = count($pages);

        foreach ($pages as $pageIndex => $pageRows) {
            $pageStreams[] = $this->renderPage(
                $pageRows,
                $context,
                $pageIndex + 1,
                $pageCount,
            );
        }

        return $this->assemble($pageStreams);
    }

    /**
     * @param  array<int, array<string, int|string>>  $rows
     * @param  array<string, int|string>  $context
     */
    private function renderPage(array $rows, array $context, int $pageNumber, int $pageCount): string
    {
        $content = '';

        $this->rectangle($content, 22, 20, self::PAGE_WIDTH - 44, 78, '#111827');
        $this->rectangle($content, 22, 20, 7, 78, '#F59E0B');
        $this->text($content, 43, 42, 'LOGISTIK TAMAN AIR HANDAYANI PAITON', 16, true, '#FFFFFF');
        $this->text($content, 43, 61, 'Laporan stok barang seluruh gudang', 8.5, false, '#CBD5E1');
        $this->text(
            $content,
            43,
            78,
            'Dibuat '.$this->cleanText((string) ($context['generated_at'] ?? '')).' WIB',
            7.5,
            false,
            '#94A3B8',
        );
        $this->text($content, 735, 43, 'LAPORAN STOK', 9, true, '#FBBF24', 'right');
        $this->text($content, 797, 64, 'READ ONLY', 7, true, '#CBD5E1', 'right');

        $cardWidth = 248;
        $this->summaryCard(
            $content,
            22,
            108,
            $cardWidth,
            'DAFTAR BARANG',
            number_format((int) ($context['total_items'] ?? 0), 0, ',', '.').' barang',
        );
        $this->summaryCard(
            $content,
            282,
            108,
            $cardWidth,
            'TOTAL STOK TERFILTER',
            number_format((int) ($context['total_stock'] ?? 0), 0, ',', '.').' unit',
        );
        $this->summaryCard(
            $content,
            542,
            108,
            277,
            'FILTER AKTIF',
            $this->fitText((string) ($context['filter_description'] ?? 'Semua gudang'), 263, 9.5),
        );

        $search = trim((string) ($context['search'] ?? ''));
        $description = $search === ''
            ? 'Seluruh data barang ditampilkan, termasuk barang yang belum mempunyai stok.'
            : 'Pencarian: "'.$search.'" - barang tanpa stok yang sesuai pencarian tetap ditampilkan.';
        $this->text($content, 24, 155, $this->fitText($description, 790, 7.5), 7.5, false, '#475569');

        $tableTop = 166;
        $headerHeight = 23;
        $rowHeight = 19;
        $x = 22;

        foreach (self::COLUMNS as $column) {
            $this->rectangle($content, $x, $tableTop, $column['width'], $headerHeight, '#1E3A5F');
            $align = $column['align'] ?? 'left';
            $textX = $align === 'right' ? $x + $column['width'] - 5 : $x + 5;
            $this->text($content, $textX, $tableTop + 15, $column['label'], 7.2, true, '#FFFFFF', $align);
            $x += $column['width'];
        }

        if ($rows === []) {
            $this->rectangle($content, 22, $tableTop + $headerHeight, 797, 48, '#F8FAFC');
            $this->text($content, 384, $tableTop + 52, 'Tidak ada data yang sesuai dengan filter.', 9, true, '#64748B', 'center');
        }

        foreach ($rows as $rowIndex => $row) {
            $top = $tableTop + $headerHeight + ($rowIndex * $rowHeight);
            $isEmptyStock = (int) ($row['stok'] ?? 0) === 0;
            $background = $isEmptyStock
                ? '#FFF7ED'
                : ($rowIndex % 2 === 0 ? '#FFFFFF' : '#F8FAFC');
            $this->rectangle($content, 22, $top, 797, $rowHeight, $background);
            $this->line($content, 22, $top + $rowHeight, 819, $top + $rowHeight, '#E2E8F0', 0.35);

            $x = 22;
            foreach (self::COLUMNS as $column) {
                $key = $column['key'];
                $value = (string) ($row[$key] ?? '-');

                if (in_array($key, ['stok_baik', 'stok_rusak', 'stok_hilang', 'stok'], true)) {
                    $value = number_format((int) $value, 0, ',', '.');
                }

                $align = $column['align'] ?? 'left';
                $textX = $align === 'right' ? $x + $column['width'] - 5 : $x + 5;
                $color = match ($key) {
                    'stok_rusak' => '#B91C1C',
                    'stok_hilang' => '#B45309',
                    'stok' => '#0F172A',
                    default => '#334155',
                };
                $bold = in_array($key, ['nama_barang', 'stok'], true);
                $this->text(
                    $content,
                    $textX,
                    $top + 13,
                    $this->fitText($value, $column['width'] - 10, 7.1),
                    7.1,
                    $bold,
                    $color,
                    $align,
                );
                $x += $column['width'];
            }
        }

        $footerTop = 570;
        $this->line($content, 22, $footerTop, 819, $footerTop, '#CBD5E1', 0.45);
        $this->text($content, 22, 584, 'Dokumen monitoring stok - tidak mengubah data sistem', 6.8, false, '#64748B');
        $this->text($content, 819, 584, "Halaman {$pageNumber} dari {$pageCount}", 6.8, true, '#475569', 'right');

        return $content;
    }

    private function summaryCard(
        string &$content,
        float $x,
        float $top,
        float $width,
        string $label,
        string $value,
    ): void {
        $this->rectangle($content, $x, $top, $width, 35, '#F8FAFC', '#CBD5E1');
        $this->text($content, $x + 10, $top + 13, $label, 6.2, true, '#64748B');
        $this->text($content, $x + 10, $top + 28, $value, 9.5, true, '#0F172A');
    }

    /** @param array<int, string> $pageStreams */
    private function assemble(array $pageStreams): string
    {
        $objects = [
            1 => '',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pageObjectIds = [];

        foreach ($pageStreams as $stream) {
            $contentObjectId = count($objects) + 1;
            $objects[$contentObjectId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $pageObjectId = count($objects) + 1;
            $objects[$pageObjectId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObjectId,
            );
            $pageObjectIds[] = $pageObjectId;
        }

        $kids = implode(' ', array_map(static fn (int $id): string => "{$id} 0 R", $pageObjectIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageObjectIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= count($objects); $id++) {
            if (! isset($offsets[$id])) {
                throw new RuntimeException('Struktur objek PDF tidak valid.');
            }

            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= 'trailer'."\n";
        $pdf .= '<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function rectangle(
        string &$content,
        float $x,
        float $top,
        float $width,
        float $height,
        string $fill,
        ?string $stroke = null,
    ): void {
        $y = self::PAGE_HEIGHT - $top - $height;
        [$red, $green, $blue] = $this->rgb($fill);
        $operator = 'f';
        $strokeCommand = '';

        if ($stroke !== null) {
            [$strokeRed, $strokeGreen, $strokeBlue] = $this->rgb($stroke);
            $strokeCommand = sprintf(' %.3F %.3F %.3F RG', $strokeRed, $strokeGreen, $strokeBlue);
            $operator = 'B';
        }

        $content .= sprintf(
            "q %.3F %.3F %.3F rg%s %.2F %.2F %.2F %.2F re %s Q\n",
            $red,
            $green,
            $blue,
            $strokeCommand,
            $x,
            $y,
            $width,
            $height,
            $operator,
        );
    }

    private function line(
        string &$content,
        float $x1,
        float $top1,
        float $x2,
        float $top2,
        string $color,
        float $width,
    ): void {
        [$red, $green, $blue] = $this->rgb($color);
        $y1 = self::PAGE_HEIGHT - $top1;
        $y2 = self::PAGE_HEIGHT - $top2;
        $content .= sprintf(
            "q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n",
            $red,
            $green,
            $blue,
            $width,
            $x1,
            $y1,
            $x2,
            $y2,
        );
    }

    private function text(
        string &$content,
        float $x,
        float $topBaseline,
        string $text,
        float $size,
        bool $bold,
        string $color,
        string $align = 'left',
    ): void {
        $encoded = $this->encode($text);
        $estimatedWidth = strlen($encoded) * $size * 0.49;
        $x = match ($align) {
            'right' => $x - $estimatedWidth,
            'center' => $x - ($estimatedWidth / 2),
            default => $x,
        };
        $y = self::PAGE_HEIGHT - $topBaseline;
        [$red, $green, $blue] = $this->rgb($color);
        $font = $bold ? 'F2' : 'F1';
        $content .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font,
            $size,
            $red,
            $green,
            $blue,
            $x,
            $y,
            $this->escape($encoded),
        );
    }

    private function fitText(string $text, float $width, float $fontSize): string
    {
        $text = $this->cleanText($text);
        $encoded = $this->encode($text);
        $maxCharacters = max(1, (int) floor($width / ($fontSize * 0.49)));

        if (strlen($encoded) <= $maxCharacters) {
            return $text;
        }

        $trimmed = substr($encoded, 0, max(1, $maxCharacters - 3)).'...';

        return mb_convert_encoding($trimmed, 'UTF-8', 'Windows-1252');
    }

    private function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function encode(string $text): string
    {
        $text = $this->cleanText($text);
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $encoded === false ? '' : $encoded;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text);
    }

    /** @return array{float, float, float} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
