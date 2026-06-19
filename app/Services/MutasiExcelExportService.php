<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\Mutasi;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use XMLWriter;
use ZipArchive;

class MutasiExcelExportService
{
    private const HEADERS = [
        'No.', 'Tanggal', 'Kode Barang', 'Nama Barang', 'Jenis', 'Jumlah', 'Satuan',
        'Sumber Barang', 'Lokasi Tujuan', 'No. Referensi', 'Status', 'Stok Awal',
        'Stok Akhir', 'Dicatat Oleh',
    ];

    public function download(Builder $query, array $context = []): StreamedResponse
    {
        $path = $this->build($query, $context);
        $fileName = 'laporan_mutasi_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($path): void {
            try {
                $stream = fopen($path, 'rb');

                if ($stream === false) {
                    throw new \RuntimeException('File export tidak dapat dibaca.');
                }

                fpassthru($stream);
                fclose($stream);
            } finally {
                @unlink($path);
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function build(Builder $query, array $context = []): string
    {
        $baseQuery = clone $query;
        $totalRows = (clone $baseQuery)->reorder()->count();
        $directory = storage_path('app/temp-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Folder sementara export tidak dapat dibuat.');
        }

        $token = bin2hex(random_bytes(8));
        $reportPath = "{$directory}/report-{$token}.xml";
        $xlsxPath = "{$directory}/mutasi-{$token}.xlsx";

        try {
            $this->writeReportSheet($reportPath, $baseQuery, $context, $totalRows);
            $this->createWorkbook($xlsxPath, $reportPath);
        } catch (\Throwable $exception) {
            @unlink($xlsxPath);

            throw $exception;
        } finally {
            @unlink($reportPath);
        }

        return $xlsxPath;
    }

    private function writeReportSheet(
        string $path,
        Builder $query,
        array $context,
        int $totalRows,
    ): void {
        $writer = $this->openWorksheet($path);
        $this->writeSheetView($writer, frozenRows: 5);

        $writer->startElement('cols');
        foreach ([6, 14, 16, 30, 12, 12, 11, 28, 32, 18, 14, 12, 12, 22] as $index => $width) {
            $this->writeColumn($writer, $index + 1, $width);
        }
        $writer->endElement();

        $writer->startElement('sheetData');
        $this->writeRow($writer, 1, [
            $this->textCell('A1', 'LAPORAN MUTASI BARANG GUDANG PT ISS INDONESIA', 1),
        ], 36);
        $this->writeRow($writer, 2, [
            $this->textCell('A2', 'AREA PAITON ENERGY', 2),
        ], 24);
        $metadata = 'Total '.number_format($totalRows, 0, ',', '.').' transaksi'
            .' | '.$this->filterDescription($context)
            .' | Diekspor '.now()->locale('id')->translatedFormat('d F Y, H:i').' WIB';
        $this->writeRow($writer, 3, [
            $this->textCell('A3', $metadata, 3),
        ], 24);

        $headerCells = [];
        foreach (self::HEADERS as $index => $header) {
            $headerCells[] = $this->textCell($this->cellReference($index + 1, 5), $header, 4);
        }
        $this->writeRow($writer, 5, $headerCells, 30);

        $rowNumber = 6;
        $sequence = 1;
        $query
            ->with([
                'barang:id,nama_barang,kode_barang,satuan',
                'lokasi:id,nama_lokasi',
                'lokasiTujuan:id,nama_lokasi,jenis_lokasi',
                'user:id,name',
            ])
            ->lazy(500)
            ->each(function (Mutasi $mutasi) use ($writer, &$rowNumber, &$sequence): void {
                $style = $rowNumber % 2 === 0 ? 7 : 6;
                $type = strtolower(trim((string) $mutasi->jenis_mutasi));
                $source = $type === 'masuk'
                    ? 'Pengadaan / Barang Baru'
                    : ($mutasi->lokasi?->nama_lokasi ?? '-');
                $destination = $type === 'masuk'
                    ? ($mutasi->lokasi?->nama_lokasi ?? '-')
                    : $this->destinationLabel($mutasi);

                $this->writeRow($writer, $rowNumber, [
                    $this->numberCell("A{$rowNumber}", $sequence, $style),
                    $this->textCell("B{$rowNumber}", $mutasi->tanggal?->format('d/m/Y') ?? '-', $style),
                    $this->textCell("C{$rowNumber}", $mutasi->barang?->kode_barang ?? '-', $style),
                    $this->textCell("D{$rowNumber}", $mutasi->barang?->nama_barang ?? '-', $style),
                    $this->textCell("E{$rowNumber}", $type === 'keluar' ? 'Keluar' : 'Masuk', $style),
                    $this->numberCell("F{$rowNumber}", (int) $mutasi->jumlah, $style),
                    $this->textCell("G{$rowNumber}", $mutasi->barang?->satuan ?? '-', $style),
                    $this->textCell("H{$rowNumber}", $source, $style),
                    $this->textCell("I{$rowNumber}", $destination, $style),
                    $this->textCell("J{$rowNumber}", $mutasi->no_ref ?: '-', $style),
                    $this->textCell("K{$rowNumber}", $this->statusLabel($mutasi->status), $style),
                    $this->nullableNumberCell("L{$rowNumber}", $mutasi->stok_awal, $style),
                    $this->nullableNumberCell("M{$rowNumber}", $mutasi->stok_akhir, $style),
                    $this->textCell("N{$rowNumber}", $mutasi->user?->name ?? '-', $style),
                ], 24);

                $rowNumber++;
                $sequence++;
            });

        if ($totalRows === 0) {
            $this->writeRow($writer, 6, [
                $this->textCell('A6', 'Tidak ada data yang sesuai dengan filter aktif.', 8),
            ], 34);
        }
        $writer->endElement();

        $writer->startElement('autoFilter');
        $writer->writeAttribute('ref', 'A5:N'.max(5, $rowNumber - 1));
        $writer->endElement();

        $writer->startElement('mergeCells');
        $mergeRanges = $totalRows === 0
            ? ['A1:N1', 'A2:N2', 'A3:N3', 'A6:N6']
            : ['A1:N1', 'A2:N2', 'A3:N3'];
        foreach ($mergeRanges as $range) {
            $writer->startElement('mergeCell');
            $writer->writeAttribute('ref', $range);
            $writer->endElement();
        }
        $writer->endElement();
        $this->writePageMargins($writer);
        $writer->startElement('pageSetup');
        $writer->writeAttribute('orientation', 'landscape');
        $writer->writeAttribute('paperSize', '9');
        $writer->writeAttribute('fitToWidth', '1');
        $writer->writeAttribute('fitToHeight', '0');
        $writer->endElement();
        $this->closeWorksheet($writer);
    }

    private function createWorkbook(string $path, string $reportPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Workbook Excel tidak dapat dibuat.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFile($reportPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
    }

    private function openWorksheet(string $path): XMLWriter
    {
        $writer = new XMLWriter;
        $writer->openUri($path);
        $writer->startDocument('1.0', 'UTF-8', 'yes');
        $writer->startElement('worksheet');
        $writer->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $writer;
    }

    private function closeWorksheet(XMLWriter $writer): void
    {
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    private function writeSheetView(XMLWriter $writer, int $frozenRows = 0): void
    {
        $writer->startElement('sheetViews');
        $writer->startElement('sheetView');
        $writer->writeAttribute('workbookViewId', '0');

        if ($frozenRows > 0) {
            $writer->startElement('pane');
            $writer->writeAttribute('ySplit', (string) $frozenRows);
            $writer->writeAttribute('topLeftCell', 'A'.($frozenRows + 1));
            $writer->writeAttribute('activePane', 'bottomLeft');
            $writer->writeAttribute('state', 'frozen');
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
    }

    private function writeRow(XMLWriter $writer, int $row, array $cells, int $height = 22): void
    {
        $writer->startElement('row');
        $writer->writeAttribute('r', (string) $row);
        $writer->writeAttribute('ht', (string) $height);
        $writer->writeAttribute('customHeight', '1');
        foreach ($cells as $cell) {
            $writer->writeRaw($cell);
        }
        $writer->endElement();
    }

    private function textCell(string $reference, mixed $value, int $style = 0): string
    {
        $text = htmlspecialchars($this->sanitizeText((string) $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<c r=\"{$reference}\" s=\"{$style}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$text}</t></is></c>";
    }

    private function numberCell(string $reference, int|float $value, int $style = 0): string
    {
        return "<c r=\"{$reference}\" s=\"{$style}\" t=\"n\"><v>{$value}</v></c>";
    }

    private function nullableNumberCell(string $reference, mixed $value, int $style): string
    {
        return is_null($value)
            ? $this->textCell($reference, '-', $style)
            : $this->numberCell($reference, (int) $value, $style);
    }

    private function writeColumn(XMLWriter $writer, int $column, float $width): void
    {
        $writer->startElement('col');
        $writer->writeAttribute('min', (string) $column);
        $writer->writeAttribute('max', (string) $column);
        $writer->writeAttribute('width', (string) $width);
        $writer->writeAttribute('customWidth', '1');
        $writer->endElement();
    }

    private function writePageMargins(XMLWriter $writer): void
    {
        $writer->startElement('pageMargins');
        foreach (['left' => '0.25', 'right' => '0.25', 'top' => '0.5', 'bottom' => '0.5', 'header' => '0.2', 'footer' => '0.2'] as $name => $value) {
            $writer->writeAttribute($name, $value);
        }
        $writer->endElement();
    }

    private function destinationLabel(Mutasi $mutasi): string
    {
        $destination = $mutasi->lokasiTujuan;

        if (! $destination) {
            return '-';
        }

        $type = Lokasi::jenisOptions()[$destination->jenis_lokasi] ?? 'Lokasi';

        return "{$destination->nama_lokasi} ({$type})";
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Disetujui',
            'cancelled' => 'Dibatalkan',
            default => 'Pending',
        };
    }

    private function filterDescription(array $context): string
    {
        $filters = $context['filters'] ?? [];
        $labels = [];
        $from = data_get($filters, 'rentang_tanggal.from');
        $to = data_get($filters, 'rentang_tanggal.to');
        $status = data_get($filters, 'status.value');
        $type = data_get($filters, 'jenis_mutasi.value');

        if ($from || $to) {
            $labels[] = 'Tanggal '.($from ?: 'awal').' s.d. '.($to ?: 'sekarang');
        }
        if ($status) {
            $labels[] = 'Status: '.$this->statusLabel($status);
        }
        if ($type) {
            $labels[] = 'Jenis: '.($type === 'keluar' ? 'Keluar' : 'Masuk');
        }
        if (filled($context['search'] ?? null)) {
            $labels[] = 'Pencarian: '.$context['search'];
        }

        return $labels ? implode(' | ', $labels) : 'Seluruh data pada tab aktif';
    }

    private function cellReference(int $column, int $row): string
    {
        $letters = '';
        while ($column > 0) {
            $column--;
            $letters = chr(65 + ($column % 26)).$letters;
            $column = intdiv($column, 26);
        }

        return $letters.$row;
    }

    private function sanitizeText(string $value): string
    {
        return preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? '';
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private function rootRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function workbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<bookViews><workbookView activeTab="0"/></bookViews>
<sheets>
<sheet name="Laporan Mutasi" sheetId="1" r:id="rId1"/>
</sheets>
</workbook>
XML;
    }

    private function workbookRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function appPropertiesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
<Application>Warehouse Monitoring</Application>
<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>
<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Laporan Mutasi</vt:lpstr></vt:vector></TitlesOfParts>
</Properties>
XML;
    }

    private function corePropertiesXml(): string
    {
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $creator = htmlspecialchars(auth()->user()?->name ?? 'Warehouse Monitoring', ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
<dc:title>Laporan Mutasi Barang</dc:title><dc:creator>{$creator}</dc:creator>
<dcterms:created xsi:type="dcterms:W3CDTF">{$timestamp}</dcterms:created>
<dcterms:modified xsi:type="dcterms:W3CDTF">{$timestamp}</dcterms:modified>
</cp:coreProperties>
XML;
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="5">
<font><sz val="10"/><name val="Aptos"/></font>
<font><b/><sz val="18"/><color rgb="FF0F172A"/><name val="Aptos Display"/></font>
<font><sz val="10"/><color rgb="FF64748B"/><name val="Aptos"/></font>
<font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Aptos"/></font>
<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Aptos"/></font>
</fonts>
<fills count="7">
<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFFFE7A3"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFEEF3F8"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFFFFAE8"/></patternFill></fill>
</fills>
<borders count="2">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border><left style="thin"><color rgb="FFD8E0E9"/></left><right style="thin"><color rgb="FFD8E0E9"/></right><top style="thin"><color rgb="FFD8E0E9"/></top><bottom style="thin"><color rgb="FFD8E0E9"/></bottom><diagonal/></border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="10">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="3" fontId="4" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }
}
