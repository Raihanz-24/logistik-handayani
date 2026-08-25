<?php

namespace Tests\Unit;

use App\Services\MutasiExcelExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class MutasiExcelExportServiceTest extends TestCase
{
    public function test_report_sheet_only_contains_requested_visible_columns(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('with')->once()->andReturnSelf();
        $query->shouldReceive('lazy')->once()->with(500)->andReturn(LazyCollection::make([]));

        $path = tempnam(sys_get_temp_dir(), 'mutasi-export-');
        $method = new ReflectionMethod(MutasiExcelExportService::class, 'writeReportSheet');

        try {
            $method->invoke(
                new MutasiExcelExportService,
                $path,
                $query,
                ['columns' => ['tanggal', 'posisiRakTujuan.kode']],
                0,
            );

            $xml = file_get_contents($path);

            $this->assertIsString($xml);
            $this->assertStringContainsString('No.', $xml);
            $this->assertStringContainsString('Tanggal', $xml);
            $this->assertStringContainsString('Rak Tujuan', $xml);
            $this->assertStringNotContainsString('Rak Asal', $xml);
            $this->assertStringNotContainsString('Status', $xml);
            $this->assertStringContainsString('ref="A5:C5"', $xml);
        } finally {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
