<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockExcelExportService
{
    private const HEADERS = [
        'No.', 'Kode Barang', 'Nama Barang', 'Gudang', 'Posisi Rak',
        'Baik', 'Rusak', 'Hilang', 'Total', 'Satuan',
    ];

    public function __construct(private readonly HistoricalStockService $historicalStockService) {}

    public function download(array $context = []): StreamedResponse
    {
        $report = $this->historicalStockService->reportData($context);
        $path = $this->buildFromReport($report);
        $date = (string) $report['context']['as_of_date'];
        $fileName = 'rekap_stok_'.$date.'_'.now('Asia/Jakarta')->format('His').'.xlsx';

        return response()->streamDownload(function () use ($path): void {
            try {
                $stream = fopen($path, 'rb');

                if ($stream === false) {
                    throw new RuntimeException('File Excel stok tidak dapat dibaca.');
                }

                fpassthru($stream);
                fclose($stream);
            } finally {
                @unlink($path);
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array{
     *     rows: array<int, array<string, int|string>>,
     *     context: array<string, int|string>
     * } $report
     */
    public function buildFromReport(array $report): string
    {
        $directory = storage_path('app/temp-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder sementara export tidak dapat dibuat.');
        }

        $path = $directory.'/stok-historis-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet;

        try {
            $this->writeWorkbook($spreadsheet, $report);
            (new Xlsx($spreadsheet))->save($path);
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    private function writeWorkbook(Spreadsheet $spreadsheet, array $report): void
    {
        $rows = $report['rows'];
        $context = $report['context'];
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Stok');
        $spreadsheet->getProperties()
            ->setCreator('Logistik Taman Air Handayani Paiton')
            ->setTitle('Rekap Stok per '.$context['as_of_label'])
            ->setSubject('Laporan stok historis berdasarkan tanggal mutasi approved');

        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', 'REKAP STOK BARANG');
        $sheet->mergeCells('A2:J2');
        $sheet->setCellValue('A2', 'LOGISTIK TAMAN AIR HANDAYANI PAITON');
        $sheet->mergeCells('A3:J3');
        $sheet->setCellValue('A3', 'Posisi stok per '.$context['as_of_label'].' (akhir hari)');
        $sheet->mergeCells('A4:J4');
        $filterText = 'Filter: '.$context['filter_description'];
        if (filled($context['search'] ?? null)) {
            $filterText .= ' | Pencarian: '.$context['search'];
        }
        $sheet->setCellValue('A4', $filterText.' | Dibuat '.$context['generated_at'].' WIB');
        $sheet->mergeCells('A5:J5');
        $sheet->setCellValue('A5', 'Dasar perhitungan: hanya mutasi berstatus approved sampai akhir tanggal rekap.');

        $sheet->setCellValue('A6', 'Jumlah Barang');
        $sheet->setCellValue('B6', (int) $context['total_items']);
        $sheet->setCellValue('D6', 'Total Stok');
        $sheet->setCellValue('E6', (int) $context['total_stock']);

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue([$index + 1, 8], $header);
        }

        $rowNumber = 9;
        foreach ($rows as $row) {
            $textValues = [
                2 => $row['kode_barang'],
                3 => $row['nama_barang'],
                4 => $row['gudang'],
                5 => $row['rak'],
                10 => $row['satuan'],
            ];
            $numberValues = [
                1 => $row['sequence'],
                6 => $row['stok_baik'],
                7 => $row['stok_rusak'],
                8 => $row['stok_hilang'],
                9 => $row['stok'],
            ];

            foreach ($textValues as $column => $value) {
                $sheet->setCellValueExplicit([$column, $rowNumber], (string) $value, DataType::TYPE_STRING);
            }
            foreach ($numberValues as $column => $value) {
                $sheet->setCellValue([$column, $rowNumber], (int) $value);
            }

            $rowNumber++;
        }

        if ($rows === []) {
            $sheet->mergeCells('A9:J9');
            $sheet->setCellValue('A9', 'Tidak ada data yang sesuai dengan filter aktif.');
            $sheet->getStyle('A9')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $rowNumber = 10;
        }

        $lastDataRow = max(9, $rowNumber - 1);
        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
        $sheet->getStyle('A2:J2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('F97316');
        $sheet->getStyle('A3:J3')->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('334155');
        $sheet->getStyle('A4:J4')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');
        $sheet->getStyle('A1:J4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:J4')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A5:J5')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('475569');
        $sheet->getStyle('A5:J5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('A6:E6')->getFont()->setBold(true);
        $sheet->getStyle('A6:E6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF7ED');
        $sheet->getStyle('A8:J8')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A8:J8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EA580C');
        $sheet->getStyle('A8:J8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A8:J{$lastDataRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('CBD5E1');
        $sheet->getStyle("A9:J{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A9:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E9:J{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        for ($row = 9; $row <= $lastDataRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:J{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFF7ED');
            }
        }

        foreach ([
            'A' => 7, 'B' => 16, 'C' => 34, 'D' => 24, 'E' => 14,
            'F' => 11, 'G' => 11, 'H' => 11, 'I' => 11, 'J' => 12,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(8)->setRowHeight(26);
        $sheet->freezePane('A9');
        $sheet->setAutoFilter("A8:J{$lastDataRow}");
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setRight(0.3)->setBottom(0.5)->setLeft(0.3);
        $sheet->getHeaderFooter()->setOddFooter('&LRekap stok '.$context['as_of_label'].'&RHalaman &P dari &N');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(8, 8);
    }
}
