<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MutasiResource\Pages;
use App\Models\Mutasi;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use Filament\Notifications\Notification;
use Filament\Tables\Filters\Filter;

// ===== Export =====
use App\Filament\Exports\MutasiExporter;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Actions\Exports\Enums\ExportFormat;

class MutasiResource extends Resource
{
    protected static ?string $model = Mutasi::class;

    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Mutasi';
    protected static ?string $pluralLabel = 'Mutasi';
    protected static ?string $slug = 'mutasi';

    protected static string $superAdminRole = 'super_admin';

    protected static function isSuperAdmin(): bool
    {
        $user = auth()->user();
        if (! $user) return false;

        return $user->hasRole([
            static::$superAdminRole,
            'super admin',
            'Super Admin',
            'super-admin',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Mutasi')
                ->collapsible()
                ->columns(2)
                ->schema([
                    Forms\Components\Hidden::make('status')
                        ->default('pending')
                        ->dehydrated(true),

                    Forms\Components\Hidden::make('created_by')
                        ->default(fn() => auth()->id())
                        ->dehydrated(true),

                    Forms\Components\DatePicker::make('tanggal')
                        ->label('Tanggal')
                        ->default(now())
                        ->required()
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('jenis_mutasi')
                        ->label('Jenis Mutasi')
                        ->options([
                            'masuk' => 'Masuk',
                            'keluar' => 'Keluar',
                        ])
                        ->native(false)
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state !== 'keluar') {
                                $set('lokasi_tujuan_id', null);
                            }
                        })
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('produk_id')
                        ->label('Produk')
                        ->relationship('produk', 'nama_produk')
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\TextInput::make('jumlah')
                        ->label('Jumlah')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\TextInput::make('no_ref')
                        ->label('No. Referensi')
                        ->maxLength(255)
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\TextInput::make('keterangan')
                        ->label('Keterangan')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('lokasi_id')
                        ->label(fn(Forms\Get $get) => $get('jenis_mutasi') === 'masuk'
                            ? 'Gudang Tujuan'
                            : 'Gudang Asal')
                        ->relationship('lokasi', 'nama_lokasi')
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->reactive()
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('lokasi_tujuan_id')
                        ->label('Tujuan (Lokasi/Gudang)')
                        ->relationship('lokasiTujuan', 'nama_lokasi')
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->visible(fn(Forms\Get $get) => $get('jenis_mutasi') === 'keluar')
                        ->required(fn(Forms\Get $get) => $get('jenis_mutasi') === 'keluar')
                        ->rules([
                            fn(Forms\Get $get) => function (string $attribute, $value, $fail) use ($get) {
                                if ($get('jenis_mutasi') === 'keluar' && $value && $value == $get('lokasi_id')) {
                                    $fail('Tujuan tidak boleh sama dengan lokasi asal.');
                                }
                            },
                        ])
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('user_id')
                        ->label('Dicatat oleh')
                        ->relationship('user', 'name')
                        ->default(fn() => auth()->id())
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('status_view')
                        ->label('Status')
                        ->options([
                            'pending' => 'Pending',
                            'approved' => 'Disetujui',
                            'cancelled' => 'Dibatalkan',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->default(fn(?Mutasi $record) => $record?->status ?? 'pending'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', direction: 'desc')
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('tanggal')
                    ->label('Tanggal')
                    ->dateTime('l, d F Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('jenis_mutasi')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => $state === 'keluar' ? 'Keluar' : 'Masuk'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => match ($state) {
                        'approved' => 'Disetujui',
                        'cancelled' => 'Dibatalkan',
                        default => 'Pending',
                    })
                    ->color(fn(?string $state) => match ($state) {
                        'approved' => 'success',
                        'cancelled' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('produk.nama_produk')
                    ->label('Produk')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Jumlah')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('asal_display')
                    ->label('Asal')
                    ->getStateUsing(function (Mutasi $record): string {
                        if ($record->jenis_mutasi === 'masuk') {
                            return '-';
                        }
                        return $record->lokasi?->nama_lokasi ?? '-';
                    })
                    ->sortable(query: function (Builder $query, string $direction) {
                        return $query->orderBy('lokasi_id', $direction);
                    }),

                Tables\Columns\TextColumn::make('tujuan_display')
                    ->label('Tujuan')
                    ->getStateUsing(function (Mutasi $record): string {
                        if ($record->jenis_mutasi === 'masuk') {
                            return $record->lokasi?->nama_lokasi ?? '-';
                        }
                        return $record->lokasiTujuan?->nama_lokasi ?? '-';
                    }),

                Tables\Columns\TextColumn::make('stok_akhir')
                    ->label('Stok Akhir (Gudang)')
                    ->numeric()
                    ->sortable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('stok_awal')
                    ->label('Stok Awal (Gudang)')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label('Disetujui oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Waktu Approve')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('cancel_reason')
                    ->label('Alasan Batal')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('rentang_tanggal')
                    ->label('Rentang Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->native(false),
                        Forms\Components\DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $from = $data['from'] ?? null;
                        $to   = $data['to'] ?? null;

                        if ($from) $query->whereDate('tanggal', '>=', $from);
                        if ($to)   $query->whereDate('tanggal', '<=', $to);

                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Disetujui',
                        'cancelled' => 'Dibatalkan',
                    ]),

                Tables\Filters\SelectFilter::make('jenis_mutasi')
                    ->label('Jenis Mutasi')
                    ->options([
                        'masuk' => 'Masuk',
                        'keluar' => 'Keluar',
                    ]),
            ])
            ->headerActions([
                ExportAction::make('export-excel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(MutasiExporter::class)
                    ->formats([ExportFormat::Xlsx])
                    ->fileName(fn() => 'mutasi_' . now()->format('Ymd_His')),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(Mutasi $record) => static::isSuperAdmin() && $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Setujui Mutasi')
                    ->modalDescription('Apakah Anda yakin ingin menyetujui mutasi ini? Setelah disetujui, stok akan diperbarui dan data tidak bisa diubah.')
                    ->action(function (Mutasi $record) {
                        try {
                            DB::transaction(function () use ($record) {
                                $record->refresh();

                                if ($record->status !== 'pending') {
                                    throw new \RuntimeException('Mutasi bukan status pending.');
                                }

                                $produkId = (int) $record->produk_id;
                                $lokasiGudangId = (int) $record->lokasi_id; // untuk masuk = gudang tujuan, untuk keluar = gudang asal
                                $jumlah = (int) $record->jumlah;

                                $pivotGudang = DB::table('produk_lokasi')
                                    ->where('produk_id', $produkId)
                                    ->where('lokasi_id', $lokasiGudangId)
                                    ->lockForUpdate()
                                    ->first();

                                $stokAwal = (int) ($pivotGudang->stok ?? 0);

                                if ($record->jenis_mutasi === 'keluar') {
                                    if ((int) $record->lokasi_tujuan_id === $lokasiGudangId) {
                                        throw new \RuntimeException('Tujuan tidak boleh sama dengan lokasi asal.');
                                    }

                                    if ($stokAwal < $jumlah) {
                                        throw new \RuntimeException('Stok gudang asal tidak mencukupi.');
                                    }

                                    $stokAkhir = $stokAwal - $jumlah;
                                } else {
                                    // masuk -> tambah stok di gudang tujuan (lokasi_id)
                                    $stokAkhir = $stokAwal + $jumlah;
                                }

                                DB::table('produk_lokasi')->updateOrInsert(
                                    ['produk_id' => $produkId, 'lokasi_id' => $lokasiGudangId],
                                    [
                                        'stok' => $stokAkhir,
                                        'updated_at' => now(),
                                        'created_at' => $pivotGudang ? ($pivotGudang->created_at ?? now()) : now(),
                                    ]
                                );

                                // transfer internal (keluar + tujuan)
                                if ($record->jenis_mutasi === 'keluar' && $record->lokasi_tujuan_id) {
                                    $lokasiTujuanId = (int) $record->lokasi_tujuan_id;

                                    $pivotTujuan = DB::table('produk_lokasi')
                                        ->where('produk_id', $produkId)
                                        ->where('lokasi_id', $lokasiTujuanId)
                                        ->lockForUpdate()
                                        ->first();

                                    $stokTujuanAwal = (int) ($pivotTujuan->stok ?? 0);
                                    $stokTujuanAkhir = $stokTujuanAwal + $jumlah;

                                    DB::table('produk_lokasi')->updateOrInsert(
                                        ['produk_id' => $produkId, 'lokasi_id' => $lokasiTujuanId],
                                        [
                                            'stok' => $stokTujuanAkhir,
                                            'updated_at' => now(),
                                            'created_at' => $pivotTujuan ? ($pivotTujuan->created_at ?? now()) : now(),
                                        ]
                                    );
                                }

                                $record->update([
                                    'stok_awal' => $stokAwal,
                                    'stok_akhir' => $stokAkhir,
                                    'status' => 'approved',
                                    'approved_by' => auth()->id(),
                                    'approved_at' => now(),
                                ]);
                            });

                            Notification::make()
                                ->title('Berhasil')
                                ->body('Mutasi berhasil disetujui dan stok gudang diperbarui.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Gagal Approve')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(Mutasi $record) => static::isSuperAdmin() && $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('cancel_reason')
                            ->label('Alasan pembatalan')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Mutasi')
                    ->modalDescription('Mutasi akan dibatalkan dan tidak bisa di-approve. Lanjutkan?')
                    ->action(function (Mutasi $record, array $data) {
                        try {
                            DB::transaction(function () use ($record, $data) {
                                $record->refresh();

                                if ($record->status !== 'pending') {
                                    throw new \RuntimeException('Mutasi bukan status pending.');
                                }

                                $record->update([
                                    'status' => 'cancelled',
                                    'cancelled_by' => auth()->id(),
                                    'cancelled_at' => now(),
                                    'cancel_reason' => $data['cancel_reason'] ?? null,
                                ]);
                            });

                            Notification::make()
                                ->title('Berhasil')
                                ->body('Mutasi berhasil dibatalkan.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Gagal')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make('export-selected')
                        ->label('Export Terpilih')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->exporter(MutasiExporter::class)
                        ->formats([ExportFormat::Xlsx])
                        ->fileName(fn() => 'mutasi_terpilih_' . now()->format('Ymd_His')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMutasis::route('/'),
            'create' => Pages\CreateMutasi::route('/buat'),
            'edit' => Pages\EditMutasi::route('/{record}/ubah'),
        ];
    }
}
