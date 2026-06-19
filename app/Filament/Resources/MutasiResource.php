<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MutasiResource\Pages;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Services\MutasiExcelExportService;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Enums\ActionsPosition;
// ✅ BULK ACTION (Approve Terpilih)
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        if (! $user) {
            return false;
        }

        return $user->hasRole([
            static::$superAdminRole,
            'super admin',
            'Super Admin',
            'super-admin',
            'Admin',
            'admin',
        ]);
    }

    /**
     * Stok tersedia = stok fisik (barang_lokasi) - total pending keluar (mutasis)
     */
    public static function getStokTersedia(?int $barangId, ?int $lokasiId, ?int $excludeMutasiId = null): int
    {
        if (! $barangId || ! $lokasiId) {
            return 0;
        }

        if (! Lokasi::query()->gudang()->whereKey($lokasiId)->exists()) {
            return 0;
        }

        $stokFisik = (int) (DB::table('barang_lokasi')
            ->where('barang_id', $barangId)
            ->where('lokasi_id', $lokasiId)
            ->value('stok') ?? 0);

        $reserved = (int) Mutasi::query()
            ->where('status', 'pending')
            ->where('jenis_mutasi', 'keluar')
            ->where('barang_id', $barangId)
            ->where('lokasi_id', $lokasiId)
            ->when($excludeMutasiId, fn ($q) => $q->where('id', '!=', $excludeMutasiId))
            ->sum('jumlah');

        return max(0, $stokFisik - $reserved);
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
                        ->default(fn () => auth()->id())
                        ->dehydrated(true),

                    Forms\Components\DatePicker::make('tanggal')
                        ->label('Tanggal')
                        ->default(now())
                        ->required()
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('jenis_mutasi')
                        ->label('Jenis Mutasi')
                        ->options([
                            'masuk' => 'Masuk',
                            'keluar' => 'Keluar',
                        ])
                        ->native(false)
                        ->required()
                        ->live()
                        ->reactive()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state !== 'keluar') {
                                $set('lokasi_tujuan_id', null);
                            }
                        })
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('barang_id')
                        ->label('Barang')
                        ->relationship('barang', 'nama_barang')
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('lokasi_id')
                        ->label(fn (Forms\Get $get) => $get('jenis_mutasi') === 'masuk'
                            ? 'Gudang Tujuan'
                            : 'Gudang Asal')
                        ->relationship(
                            name: 'lokasi',
                            titleAttribute: 'nama_lokasi',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->gudang()
                                ->orderBy('nama_lokasi'),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (Lokasi $record): string => "{$record->nama_lokasi} ({$record->kode_lokasi})"
                        )
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->live()
                        ->reactive()
                        ->helperText('Hanya lokasi bertipe Gudang yang dapat menyimpan dan mengeluarkan stok.')
                        ->rules([
                            Rule::exists('lokasis', 'id')->where('jenis_lokasi', Lokasi::JENIS_GUDANG),
                        ])
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\TextInput::make('jumlah')
                        ->label('Jumlah')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->live()
                        ->helperText(function (Forms\Get $get) {
                            if ($get('jenis_mutasi') !== 'keluar') {
                                return null;
                            }

                            $barangId = (int) ($get('barang_id') ?? 0);
                            $lokasiId = (int) ($get('lokasi_id') ?? 0);

                            if (! $barangId || ! $lokasiId) {
                                return 'Pilih Barang dan Gudang Asal untuk melihat stok tersedia.';
                            }

                            $stok = static::getStokTersedia($barangId, $lokasiId);

                            return "Stok tersedia (dikurangi pending): {$stok}";
                        })
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                if ($get('jenis_mutasi') !== 'keluar') {
                                    return;
                                }

                                $barangId = (int) ($get('barang_id') ?? 0);
                                $lokasiId = (int) ($get('lokasi_id') ?? 0);
                                if (! $barangId || ! $lokasiId) {
                                    return;
                                }

                                $minta = (int) $value;
                                $stok = static::getStokTersedia($barangId, $lokasiId);

                                if ($minta > $stok) {
                                    $fail("Stok tidak mencukupi. Stok tersedia (dikurangi pending): {$stok}.");
                                }
                            },
                        ])
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\TextInput::make('no_ref')
                        ->label('No. Referensi')
                        ->maxLength(255)
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\TextInput::make('keterangan')
                        ->label('Keterangan')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('lokasi_tujuan_id')
                        ->label('Lokasi Tujuan')
                        ->relationship(
                            name: 'lokasiTujuan',
                            titleAttribute: 'nama_lokasi',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->orderBy('jenis_lokasi')
                                ->orderBy('nama_lokasi'),
                        )
                        ->getOptionLabelFromRecordUsing(function (Lokasi $record): string {
                            $jenis = Lokasi::jenisOptions()[$record->jenis_lokasi] ?? 'Lokasi';

                            return "{$record->nama_lokasi} ({$jenis})";
                        })
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->visible(fn (Forms\Get $get) => $get('jenis_mutasi') === 'keluar')
                        ->required(fn (Forms\Get $get) => $get('jenis_mutasi') === 'keluar')
                        ->helperText('Pilih gudang lain untuk transfer stok, atau Lokasi Pemakaian untuk barang habis pakai.')
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, $fail) use ($get) {
                                if ($get('jenis_mutasi') === 'keluar' && $value && $value == $get('lokasi_id')) {
                                    $fail('Tujuan tidak boleh sama dengan lokasi asal.');
                                }
                            },
                        ])
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('user_id')
                        ->label('Dicatat oleh')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->preload()
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->disabled(fn ($record) => in_array($record?->status, ['approved', 'cancelled'])),

                    Forms\Components\Select::make('status_view')
                        ->label('Status')
                        ->options([
                            'pending' => 'Pending',
                            'approved' => 'Disetujui',
                            'cancelled' => 'Dibatalkan',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->default(fn (?Mutasi $record) => $record?->status ?? 'pending'),
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
                    ->formatStateUsing(fn (?string $state) => $state === 'keluar' ? 'Keluar' : 'Masuk'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'approved' => 'Disetujui',
                        'cancelled' => 'Dibatalkan',
                        default => 'Pending',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'approved' => 'success',
                        'cancelled' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('barang.nama_barang')
                    ->label('Barang')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Jumlah')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('asal_display')
                    ->label('Sumber Barang')
                    ->getStateUsing(function (Mutasi $record): string {
                        if ($record->jenis_mutasi === 'masuk') {
                            return 'Pengadaan / Barang Baru';
                        }

                        return $record->lokasi?->nama_lokasi ?? '-';
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('lokasi_id', $direction)),

                Tables\Columns\TextColumn::make('tujuan_display')
                    ->label('Tujuan')
                    ->getStateUsing(function (Mutasi $record): string {
                        if ($record->jenis_mutasi === 'masuk') {
                            return $record->lokasi?->nama_lokasi ?? '-';
                        }
                        $tujuan = $record->lokasiTujuan;
                        if (! $tujuan) {
                            return '-';
                        }

                        $jenis = Lokasi::jenisOptions()[$tujuan->jenis_lokasi] ?? 'Lokasi';

                        return "{$tujuan->nama_lokasi} ({$jenis})";
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
                        $to = $data['to'] ?? null;

                        if ($from) {
                            $query->whereDate('tanggal', '>=', $from);
                        }
                        if ($to) {
                            $query->whereDate('tanggal', '<=', $to);
                        }

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
                Action::make('export-excel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->tooltip('Unduh sesuai tab, filter, pencarian, dan urutan tabel saat ini')
                    ->action(function ($livewire) {
                        return app(MutasiExcelExportService::class)->download(
                            query: $livewire->getTableQueryForExport(),
                            context: [
                                'active_tab' => $livewire->activeTab ?? null,
                                'filters' => $livewire->tableFilters ?? [],
                                'search' => $livewire->tableSearch ?? null,
                            ],
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Mutasi $record) => static::isSuperAdmin() && $record->status === 'pending')
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

                                $barangId = (int) $record->barang_id;
                                $lokasiGudangId = (int) $record->lokasi_id;
                                $jumlah = (int) $record->jumlah;

                                $gudang = Lokasi::query()->find($lokasiGudangId);
                                if (! $gudang?->isGudang()) {
                                    throw new \RuntimeException('Lokasi stok harus bertipe Gudang.');
                                }

                                // ✅ FIX: normalisasi jenis_mutasi biar "Keluar" / "keluar " tetap kebaca keluar
                                $jenis = strtolower(trim((string) $record->jenis_mutasi));

                                $pivotGudang = DB::table('barang_lokasi')
                                    ->where('barang_id', $barangId)
                                    ->where('lokasi_id', $lokasiGudangId)
                                    ->lockForUpdate()
                                    ->first();

                                $stokAwal = (int) ($pivotGudang->stok ?? 0);

                                if ($jenis === 'keluar') {
                                    if ((int) $record->lokasi_tujuan_id === $lokasiGudangId) {
                                        throw new \RuntimeException('Tujuan tidak boleh sama dengan lokasi asal.');
                                    }

                                    if ($stokAwal < $jumlah) {
                                        throw new \RuntimeException('Stok gudang asal tidak mencukupi.');
                                    }

                                    $stokAkhir = $stokAwal - $jumlah;
                                } else {
                                    $stokAkhir = $stokAwal + $jumlah;
                                }

                                DB::table('barang_lokasi')->updateOrInsert(
                                    ['barang_id' => $barangId, 'lokasi_id' => $lokasiGudangId],
                                    [
                                        'stok' => $stokAkhir,
                                        'updated_at' => now(),
                                        'created_at' => $pivotGudang ? ($pivotGudang->created_at ?? now()) : now(),
                                    ]
                                );

                                if ($jenis === 'keluar' && $record->lokasiTujuan?->isGudang()) {
                                    $lokasiTujuanId = (int) $record->lokasi_tujuan_id;

                                    $pivotTujuan = DB::table('barang_lokasi')
                                        ->where('barang_id', $barangId)
                                        ->where('lokasi_id', $lokasiTujuanId)
                                        ->lockForUpdate()
                                        ->first();

                                    $stokTujuanAwal = (int) ($pivotTujuan->stok ?? 0);
                                    $stokTujuanAkhir = $stokTujuanAwal + $jumlah;

                                    DB::table('barang_lokasi')->updateOrInsert(
                                        ['barang_id' => $barangId, 'lokasi_id' => $lokasiTujuanId],
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
                    ->visible(fn (Mutasi $record) => static::isSuperAdmin() && $record->status === 'pending')
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
            ], ActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->label('Approve Terpilih')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve Mutasi Terpilih')
                        ->modalDescription('Semua mutasi yang dipilih (status pending) akan di-approve dan stok akan diperbarui.')
                        ->visible(function ($livewire) {
                            if (! static::isSuperAdmin()) {
                                return false;
                            }

                            $activeTab = $livewire->activeTab ?? null;

                            return $activeTab === null || $activeTab === 'Pending';
                        })
                        ->action(function (Collection $records) {
                            $approved = 0;
                            $skipped = 0;
                            $errors = [];

                            foreach ($records as $rec) {
                                /** @var Mutasi $rec */
                                try {
                                    DB::transaction(function () use ($rec, &$approved, &$skipped) {
                                        $record = Mutasi::query()->lockForUpdate()->findOrFail($rec->id);

                                        if ($record->status !== 'pending') {
                                            $skipped++;

                                            return;
                                        }

                                        $barangId = (int) $record->barang_id;
                                        $lokasiGudangId = (int) $record->lokasi_id;
                                        $jumlah = (int) $record->jumlah;

                                        $gudang = Lokasi::query()->find($lokasiGudangId);
                                        if (! $gudang?->isGudang()) {
                                            throw new \RuntimeException('Lokasi stok harus bertipe Gudang.');
                                        }

                                        // ✅ FIX: normalisasi jenis_mutasi
                                        $jenis = strtolower(trim((string) $record->jenis_mutasi));

                                        $pivotGudang = DB::table('barang_lokasi')
                                            ->where('barang_id', $barangId)
                                            ->where('lokasi_id', $lokasiGudangId)
                                            ->lockForUpdate()
                                            ->first();

                                        $stokAwal = (int) ($pivotGudang->stok ?? 0);

                                        if ($jenis === 'keluar') {
                                            if ((int) $record->lokasi_tujuan_id === $lokasiGudangId) {
                                                throw new \RuntimeException('Tujuan tidak boleh sama dengan lokasi asal.');
                                            }

                                            if ($stokAwal < $jumlah) {
                                                throw new \RuntimeException('Stok gudang asal tidak mencukupi.');
                                            }

                                            $stokAkhir = $stokAwal - $jumlah;
                                        } else {
                                            $stokAkhir = $stokAwal + $jumlah;
                                        }

                                        DB::table('barang_lokasi')->updateOrInsert(
                                            ['barang_id' => $barangId, 'lokasi_id' => $lokasiGudangId],
                                            [
                                                'stok' => $stokAkhir,
                                                'updated_at' => now(),
                                                'created_at' => $pivotGudang ? ($pivotGudang->created_at ?? now()) : now(),
                                            ]
                                        );

                                        if ($jenis === 'keluar' && $record->lokasiTujuan?->isGudang()) {
                                            $lokasiTujuanId = (int) $record->lokasi_tujuan_id;

                                            $pivotTujuan = DB::table('barang_lokasi')
                                                ->where('barang_id', $barangId)
                                                ->where('lokasi_id', $lokasiTujuanId)
                                                ->lockForUpdate()
                                                ->first();

                                            $stokTujuanAwal = (int) ($pivotTujuan->stok ?? 0);
                                            $stokTujuanAkhir = $stokTujuanAwal + $jumlah;

                                            DB::table('barang_lokasi')->updateOrInsert(
                                                ['barang_id' => $barangId, 'lokasi_id' => $lokasiTujuanId],
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

                                        $approved++;
                                    });
                                } catch (\Throwable $e) {
                                    $errors[] = "ID {$rec->id}: ".$e->getMessage();
                                }
                            }

                            $body = "Approved: {$approved}, Skipped: {$skipped}";
                            if (! empty($errors)) {
                                $body .= ', Error: '.count($errors).' (cek log / coba ulang)';
                            }

                            $notif = Notification::make()
                                ->title('Approve Terpilih Selesai')
                                ->body($body);

                            if (! empty($errors)) {
                                $notif->danger();
                            } else {
                                $notif->success();
                            }

                            $notif->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMutasis::route('/'),
            'create' => Pages\CreateMutasi::route('/buat'),
        ];
    }
}
