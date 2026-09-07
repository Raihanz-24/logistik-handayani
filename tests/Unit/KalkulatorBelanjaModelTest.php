<?php

namespace Tests\Unit;

use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use PHPUnit\Framework\TestCase;

class KalkulatorBelanjaModelTest extends TestCase
{
    public function test_sesi_menghitung_total_pengeluaran_dan_sisa_uang(): void
    {
        $session = new KalkulatorBelanja([
            'uang_awal' => 5_000_000,
        ]);
        $session->setRelation('pengeluaran', collect([
            new PengeluaranBelanja(['nominal' => 2_000_000]),
            new PengeluaranBelanja(['nominal' => 1_000_000]),
        ]));

        $this->assertSame(3_000_000, $session->total_pengeluaran);
        $this->assertSame(2_000_000, $session->sisa_uang);
    }

    public function test_model_menjaga_snapshot_supplier_dan_membersihkan_foto_nota_saat_dihapus(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $sessionModel = (string) file_get_contents($projectRoot.'/app/Models/KalkulatorBelanja.php');
        $expenseModel = (string) file_get_contents($projectRoot.'/app/Models/PengeluaranBelanja.php');

        $this->assertStringContainsString('$record->pengeluaran()->get()->each->delete()', $sessionModel);
        $this->assertStringContainsString('$record->receiptPathsBeforeDelete[] = $record->foto_nota', $expenseModel);
        $this->assertStringContainsString("Storage::disk('public')->delete(\$record->receiptPathsBeforeDelete)", $expenseModel);
        $this->assertStringContainsString("->value('nama_supplier')", $expenseModel);
        $this->assertStringContainsString('nama_supplier_snapshot', $expenseModel);
    }
}
