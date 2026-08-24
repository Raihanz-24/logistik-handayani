<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MutasiRakResource\Pages;
use App\Models\MutasiRak;
use App\Models\PosisiRak;
use App\Services\MutasiRakService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MutasiRakResource extends Resource
{
    protected static ?string $model = MutasiRak::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Mutasi Antar Rak';

    protected static ?string $pluralLabel = 'Mutasi Antar Rak';

    protected static ?string $modelLabel = 'Mutasi Antar Rak';

    protected static ?string $slug = 'mutasi-antar-rak';

    protected static ?int $navigationSort = 6;

    /** @return array<int, string> */
    public static function warehouseOptions(): array
    {
        return \App\Models\Lokasi::query()->gudang()->where('menggunakan_rak', true)
            ->orderBy('nama_lokasi')->pluck('nama_lokasi', 'id')->all();
    }

    /** @return array<int, string> */
    public static function stockItemOptions(?int $warehouseId): array
    {
        if (! $warehouseId) {
            return [];
        }

        return DB::table('barang_lokasi')
            ->join('barangs', 'barangs.id', '=', 'barang_lokasi.barang_id')
            ->join('posisi_raks', 'posisi_raks.id', '=', 'barang_lokasi.posisi_rak_id')
            ->where('barang_lokasi.lokasi_id', $warehouseId)
            ->where('barang_lokasi.stok', '>', 0)
            ->where('posisi_raks.aktif', true)
            ->orderBy('barangs.nama_barang')
            ->get([
                'barangs.id', 'barangs.kode_barang', 'barangs.nama_barang',
                'posisi_raks.kode as kode_rak', 'barang_lokasi.stok',
            ])->mapWithKeys(fn (object $row): array => [
                (int) $row->id => "{$row->kode_barang} - {$row->nama_barang} | {$row->kode_rak} | Stok {$row->stok}",
            ])->all();
    }

    /** @return array<int, string> */
    public static function targetPositionOptions(?int $warehouseId, ?int $barangId): array
    {
        if (! $warehouseId) {
            return [];
        }

        $sourceId = static::pivot($warehouseId, $barangId)?->posisi_rak_id;

        return PosisiRak::query()->aktif()->where('lokasi_id', $warehouseId)
            ->when($sourceId, fn (Builder $query): Builder => $query->where('id', '!=', $sourceId))
            ->orderBy('kode')->pluck('kode', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Pemindahan Posisi Barang')
                ->description('Khusus perpindahan seluruh stok barang antar-rak dalam satu gudang. Jumlah dan kondisi stok tidak berubah.')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('tanggal')->label('Tanggal')
                        ->default(now())->native(false)->required()->maxDate(now()),
                    Forms\Components\Select::make('lokasi_id')->label('Gudang')
                        ->options(fn (): array => static::warehouseOptions())
                        ->searchable()->preload()->native(false)->required()->live()
                        ->afterStateUpdated(function (Forms\Set $set): void {
                            $set('barang_id', null);
                            $set('posisi_rak_tujuan_id', null);
                        })
                        ->helperText('Hanya gudang aktif yang menggunakan rak.'),
                    Forms\Components\Select::make('barang_id')->label('Barang yang Dipindah')
                        ->options(fn (Forms\Get $get): array => static::stockItemOptions((int) $get('lokasi_id')))
                        ->searchable()->preload()->native(false)->required()->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('posisi_rak_tujuan_id', null))
                        ->helperText('Rak asal dan stok diambil otomatis dari stok gudang.'),
                    Forms\Components\Select::make('posisi_rak_tujuan_id')->label('Rak Tujuan')
                        ->options(fn (Forms\Get $get): array => static::targetPositionOptions(
                            (int) $get('lokasi_id'),
                            (int) $get('barang_id'),
                        ))
                        ->searchable()->preload()->native(false)->required()
                        ->disabled(fn (Forms\Get $get): bool => ! $get('barang_id'))
                        ->helperText('Rak tujuan harus aktif, berbeda dari rak asal, dan berada di gudang yang sama.'),
                    Forms\Components\Placeholder::make('rak_asal')->label('Rak Asal Saat Ini')
                        ->content(function (Forms\Get $get): string {
                            $pivot = static::pivot((int) $get('lokasi_id'), (int) $get('barang_id'));

                            return $pivot?->kode_rak ?? 'Pilih gudang dan barang.';
                        }),
                    Forms\Components\Placeholder::make('stok_dipindah')->label('Stok yang Dipindahkan')
                        ->content(function (Forms\Get $get): string {
                            $pivot = static::pivot((int) $get('lokasi_id'), (int) $get('barang_id'));
                            if (! $pivot) {
                                return 'Pilih gudang dan barang.';
                            }

                            return "Seluruh stok: {$pivot->stok} (Baik {$pivot->stok_baik}, Rusak {$pivot->stok_rusak}, Hilang {$pivot->stok_hilang})";
                        }),
                    Forms\Components\TextInput::make('no_ref')->label('No. Referensi')
                        ->maxLength(100)->placeholder('Opsional'),
                    Forms\Components\Textarea::make('keterangan')->label('Keterangan')
                        ->rows(3)->maxLength(1000)->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Detail Mutasi Antar Rak')->columns(3)->schema([
                TextEntry::make('id')->label('ID')->formatStateUsing(fn (int $state): string => "#{$state}"),
                TextEntry::make('tanggal')->date('d F Y'),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => MutasiRak::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        MutasiRak::STATUS_APPROVED => 'success',
                        MutasiRak::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),
                TextEntry::make('barang.nama_barang')->label('Barang'),
                TextEntry::make('lokasi.nama_lokasi')->label('Gudang'),
                TextEntry::make('createdBy.name')->label('Dibuat oleh')->placeholder('-'),
                TextEntry::make('posisiRakAsal.kode')->label('Rak Asal'),
                TextEntry::make('posisiRakTujuan.kode')->label('Rak Tujuan'),
                TextEntry::make('stok')->label('Seluruh Stok')->numeric(),
                TextEntry::make('stok_baik')->label('Baik')->numeric()->badge()->color('success'),
                TextEntry::make('stok_rusak')->label('Rusak')->numeric()->badge()->color('danger'),
                TextEntry::make('stok_hilang')->label('Hilang')->numeric()->badge()->color('gray'),
                TextEntry::make('no_ref')->label('No. Referensi')->placeholder('-'),
                TextEntry::make('keterangan')->placeholder('-')->columnSpan(2),
            ]),
            InfolistSection::make('Persetujuan')->columns(3)->schema([
                TextEntry::make('approvedBy.name')->label('Disetujui oleh')->placeholder('-'),
                TextEntry::make('approved_at')->label('Waktu Persetujuan')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('cancelledBy.name')->label('Dibatalkan oleh')->placeholder('-'),
                TextEntry::make('cancelled_at')->label('Waktu Pembatalan')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('cancel_reason')->label('Alasan Pembatalan')->placeholder('-')->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->defaultPaginationPageOption(25)
            ->paginationPageOptions([5, 25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('barang.kode_barang')->label('Kode')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('barang.nama_barang')->label('Barang')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')->label('Gudang')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('posisiRakAsal.kode')->label('Rak Asal'),
                Tables\Columns\TextColumn::make('posisiRakTujuan.kode')->label('Rak Tujuan'),
                Tables\Columns\TextColumn::make('stok')->label('Stok')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => MutasiRak::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        MutasiRak::STATUS_APPROVED => 'success',
                        MutasiRak::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Dibuat oleh')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(MutasiRak::statusOptions()),
                Tables\Filters\SelectFilter::make('lokasi_id')->label('Gudang')
                    ->options(fn (): array => static::warehouseOptions()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')->label('Setujui')->icon('heroicon-o-check-circle')
                    ->color('success')->requiresConfirmation()
                    ->modalDescription('Seluruh stok barang akan dipindahkan ke rak tujuan tanpa mengubah jumlah maupun kondisinya.')
                    ->visible(fn (MutasiRak $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(function (MutasiRak $record): void {
                        try {
                            app(MutasiRakService::class)->approve($record, (int) auth()->id());
                            Notification::make()->title('Mutasi rak disetujui')->success()->send();
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Gagal menyetujui mutasi rak')
                                ->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('cancel')->label('Batalkan')->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (MutasiRak $record): bool => $record->status === MutasiRak::STATUS_PENDING
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->form([
                        Forms\Components\Textarea::make('cancel_reason')->label('Alasan Pembatalan')
                            ->required()->maxLength(255),
                    ])
                    ->action(function (MutasiRak $record, array $data): void {
                        try {
                            app(MutasiRakService::class)->cancel(
                                $record,
                                (int) auth()->id(),
                                $data['cancel_reason'],
                            );
                            Notification::make()->title('Mutasi rak dibatalkan')->success()->send();
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Gagal membatalkan mutasi rak')
                                ->body($exception->getMessage())->danger()->send();
                        }
                    }),
            ], ActionsPosition::BeforeColumns);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'barang', 'lokasi', 'posisiRakAsal', 'posisiRakTujuan', 'createdBy', 'approvedBy', 'cancelledBy',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMutasiRaks::route('/'),
            'create' => Pages\CreateMutasiRak::route('/buat'),
            'view' => Pages\ViewMutasiRak::route('/{record}'),
        ];
    }

    private static function pivot(?int $warehouseId, ?int $barangId): ?object
    {
        if (! $warehouseId || ! $barangId) {
            return null;
        }

        return DB::table('barang_lokasi')
            ->leftJoin('posisi_raks', 'posisi_raks.id', '=', 'barang_lokasi.posisi_rak_id')
            ->where('barang_lokasi.lokasi_id', $warehouseId)
            ->where('barang_lokasi.barang_id', $barangId)
            ->first([
                'barang_lokasi.posisi_rak_id', 'barang_lokasi.stok',
                'barang_lokasi.stok_baik', 'barang_lokasi.stok_rusak', 'barang_lokasi.stok_hilang',
                'posisi_raks.kode as kode_rak',
            ]);
    }
}
