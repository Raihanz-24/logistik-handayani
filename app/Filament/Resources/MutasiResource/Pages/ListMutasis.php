<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use App\Models\Mutasi;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListMutasis extends ListRecords
{
    protected static string $resource = MutasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Buat Mutasi'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'Pending' => Tab::make()
                ->label('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'Approved' => Tab::make()
                ->label('Disetujui')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),

            'Cancelled' => Tab::make()
                ->label('Dibatalkan')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }

    protected function getTableActions(): array
    {
        return array_merge(parent::getTableActions(), [
            TableAction::make('delete_approved')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Hapus Mutasi (Rollback Stok)')
                ->modalDescription('Mutasi akan dihapus dan stok akan dikembalikan seperti sebelum mutasi disetujui.')
                ->modalSubmitActionLabel('Ya, hapus')
                ->visible(function (Mutasi $record): bool {
                    if ($record->status !== 'approved') {
                        return false;
                    }

                    if (! $this->isApprovedTabActive()) {
                        return false;
                    }

                    // ✅ sesuai permission di UI kamu: "Hapus" / "Hapus Apa Saja"
                    return auth()->check()
                        && (auth()->user()->can('delete_mutasi') || auth()->user()->can('delete_any_mutasi'));
                })
                ->action(function (Mutasi $record): void {
                    try {
                        DB::transaction(function () use ($record) {
                            $mutasi = Mutasi::query()->lockForUpdate()->findOrFail($record->id);

                            if ($mutasi->status !== 'approved') {
                                return;
                            }

                            $this->rollbackPivotStockFromApprovedMutasi($mutasi);

                            $mutasi->delete(); // permanen (Mutasi tanpa SoftDeletes)
                        });

                        Notification::make()
                            ->title('Berhasil')
                            ->body('Mutasi berhasil dihapus dan stok berhasil di-rollback.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal Hapus')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ]);
    }

    private function isApprovedTabActive(): bool
    {
        $active = null;

        if (property_exists($this, 'activeTab')) {
            $active = $this->activeTab;
        }

        if (! is_string($active) || $active === '') {
            $active = request()->query('activeTab') ?? request()->query('tab');
        }

        if (! is_string($active) || $active === '') {
            return false; // default biasanya Pending
        }

        return mb_strtolower($active) === 'approved';
    }

    private function rollbackPivotStockFromApprovedMutasi(Mutasi $mutasi): void
    {
        $produkId = (int) $mutasi->produk_id;
        $jumlah   = max(0, (int) $mutasi->jumlah);

        if ($produkId <= 0 || $jumlah <= 0) return;

        if ($mutasi->jenis_mutasi === 'masuk') {
            $lokasiGudangId = (int) $mutasi->lokasi_id;

            $pivot = DB::table('produk_lokasi')
                ->where('produk_id', $produkId)
                ->where('lokasi_id', $lokasiGudangId)
                ->lockForUpdate()
                ->first();

            $stokNow = (int) ($pivot->stok ?? 0);
            $stokNew = max(0, $stokNow - $jumlah);

            DB::table('produk_lokasi')->updateOrInsert(
                ['produk_id' => $produkId, 'lokasi_id' => $lokasiGudangId],
                [
                    'stok' => $stokNew,
                    'updated_at' => now(),
                    'created_at' => $pivot?->created_at ?? now(),
                ]
            );
            return;
        }

        if ($mutasi->jenis_mutasi === 'keluar') {
            $lokasiAsalId   = (int) $mutasi->lokasi_id;
            $lokasiTujuanId = (int) ($mutasi->lokasi_tujuan_id ?? 0);

            $pivotAsal = DB::table('produk_lokasi')
                ->where('produk_id', $produkId)
                ->where('lokasi_id', $lokasiAsalId)
                ->lockForUpdate()
                ->first();

            $stokAsalNow = (int) ($pivotAsal->stok ?? 0);
            $stokAsalNew = $stokAsalNow + $jumlah;

            DB::table('produk_lokasi')->updateOrInsert(
                ['produk_id' => $produkId, 'lokasi_id' => $lokasiAsalId],
                [
                    'stok' => $stokAsalNew,
                    'updated_at' => now(),
                    'created_at' => $pivotAsal?->created_at ?? now(),
                ]
            );

            if ($lokasiTujuanId > 0) {
                $pivotTujuan = DB::table('produk_lokasi')
                    ->where('produk_id', $produkId)
                    ->where('lokasi_id', $lokasiTujuanId)
                    ->lockForUpdate()
                    ->first();

                $stokTujuanNow = (int) ($pivotTujuan->stok ?? 0);
                $stokTujuanNew = max(0, $stokTujuanNow - $jumlah);

                DB::table('produk_lokasi')->updateOrInsert(
                    ['produk_id' => $produkId, 'lokasi_id' => $lokasiTujuanId],
                    [
                        'stok' => $stokTujuanNew,
                        'updated_at' => now(),
                        'created_at' => $pivotTujuan?->created_at ?? now(),
                    ]
                );
            }

            return;
        }
    }
}
