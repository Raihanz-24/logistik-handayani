<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MutasiResource\Pages;
use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\PosisiRak;
use App\Models\Supplier;
use App\Services\MutasiExcelExportService;
use App\Services\MutasiExcelImportService;
use App\Services\MutasiStockService;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MutasiResource extends Resource
{
    protected static ?string $model = Mutasi::class;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Mutasi';

    protected static ?string $pluralLabel = 'Mutasi';

    protected static ?string $slug = 'mutasi';

    protected static function isSuperAdmin(): bool
    {
        return auth()->user()?->hasRole(['super_admin', 'super admin', 'Super Admin', 'super-admin']) ?? false;
    }

    protected static function canApproveAny(): bool
    {
        return auth()->user()?->can('approveAny', Mutasi::class) ?? false;
    }

    protected static function canApproveRecord(Mutasi $record): bool
    {
        return $record->status === 'pending' && (auth()->user()?->can('approve', $record) ?? false);
    }

    public static function getStokTersedia(
        ?int $barangId,
        ?int $lokasiId,
        ?string $condition = null,
        ?int $excludeMutasiId = null,
    ): int {
        if (! $barangId || ! $lokasiId) {
            return 0;
        }

        $column = $condition ? BarangLokasi::conditionColumn($condition) : 'stok';
        $physical = (int) (DB::table('barang_lokasi')
            ->where('barang_id', $barangId)->where('lokasi_id', $lokasiId)
            ->value($column) ?? 0);

        $reserved = (int) Mutasi::query()
            ->where('status', 'pending')
            ->whereIn('jenis_mutasi', ['keluar', 'perubahan_kondisi'])
            ->where('barang_id', $barangId)->where('lokasi_id', $lokasiId)
            ->when($condition, fn (Builder $query): Builder => $query->where('kondisi_asal', $condition))
            ->when($excludeMutasiId, fn (Builder $query): Builder => $query->where('id', '!=', $excludeMutasiId))
            ->sum('jumlah');

        return max(0, $physical - $reserved);
    }

    /** @return array<int, string> */
    public static function availableBarangOptions(?int $lokasiId, ?string $jenis): array
    {
        if ($jenis === 'masuk') {
            return \App\Models\Barang::query()->orderBy('nama_barang')
                ->get()->mapWithKeys(fn ($item): array => [$item->id => "{$item->kode_barang} - {$item->nama_barang}"])->all();
        }
        if (! $lokasiId) {
            return [];
        }

        return DB::table('barang_lokasi')
            ->join('barangs', 'barangs.id', '=', 'barang_lokasi.barang_id')
            ->leftJoin('posisi_raks', 'posisi_raks.id', '=', 'barang_lokasi.posisi_rak_id')
            ->where('barang_lokasi.lokasi_id', $lokasiId)->where('barang_lokasi.stok', '>', 0)
            ->orderBy('barangs.nama_barang')
            ->get([
                'barangs.id', 'barangs.kode_barang', 'barangs.nama_barang', 'barang_lokasi.stok',
                'barang_lokasi.stok_baik', 'barang_lokasi.stok_rusak', 'barang_lokasi.stok_hilang',
                'posisi_raks.kode as kode_rak',
            ])->mapWithKeys(function ($item): array {
                $rack = $item->kode_rak ?: 'Tanpa rak';
                $stock = "B:{$item->stok_baik} R:{$item->stok_rusak} H:{$item->stok_hilang}";

                return [$item->id => "{$item->kode_barang} - {$item->nama_barang} | {$rack} | {$stock}"];
            })->all();
    }

    public static function needsTargetPosition(?int $barangId, ?int $warehouseId): bool
    {
        if (! $barangId || ! $warehouseId) {
            return false;
        }

        $warehouse = Lokasi::query()->find($warehouseId);
        if (! $warehouse?->isGudang() || ! $warehouse->menggunakan_rak) {
            return false;
        }

        return ! DB::table('barang_lokasi')->where('barang_id', $barangId)
            ->where('lokasi_id', $warehouseId)->whereNotNull('posisi_rak_id')->exists();
    }

    public static function warehouseUsesRacks(?int $warehouseId): bool
    {
        if (! $warehouseId) {
            return false;
        }

        return Lokasi::query()->gudang()->whereKey($warehouseId)->where('menggunakan_rak', true)->exists();
    }

    public static function fixedTargetPosition(?int $barangId, ?int $warehouseId): ?int
    {
        if (! $barangId || ! $warehouseId) {
            return null;
        }

        $positionId = DB::table('barang_lokasi')
            ->where('barang_id', $barangId)
            ->where('lokasi_id', $warehouseId)
            ->value('posisi_rak_id');

        return $positionId ? (int) $positionId : null;
    }

    public static function barangOptionLabel(mixed $value): ?string
    {
        $barang = Barang::query()->find((int) $value);

        return $barang ? "{$barang->kode_barang} - {$barang->nama_barang}" : null;
    }

    public static function barangUnit(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Barang::query()->whereKey((int) $value)->value('satuan');
    }

    public static function selectedItemBarangId(Forms\Get $get): int
    {
        return (int) ($get('barang_id') ?: $get('barang_id_terpilih'));
    }

    /** @return array<int, string> */
    public static function positionOptions(?int $warehouseId): array
    {
        return PosisiRak::query()->where('lokasi_id', $warehouseId)->aktif()
            ->orderBy('kode')->pluck('kode', 'id')->all();
    }

    /** @return array<int, string> */
    public static function targetPositionOptions(?int $barangId, ?int $warehouseId): array
    {
        $options = static::positionOptions($warehouseId);
        $fixedPositionId = static::fixedTargetPosition($barangId, $warehouseId);

        if ($fixedPositionId && isset($options[$fixedPositionId])) {
            $options[$fixedPositionId] .= ' (Rak tetap)';
        }

        return $options;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Mutasi Barang')
                ->description('Semua perubahan lokasi atau kondisi dibuat sebagai mutasi dan baru mengubah stok setelah disetujui.')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('tanggal')->label('Tanggal')->default(now())->required(),
                    Forms\Components\Select::make('jenis_mutasi')
                        ->label('Jenis Mutasi')->options(Mutasi::jenisOptions())->native(false)->required()->live()
                        ->afterStateUpdated(function (Forms\Set $set): void {
                            $set('lokasi_tujuan_id', null);
                            $set('supplier_id', null);
                            $set('items', [['kondisi_tujuan' => Mutasi::KONDISI_BAIK]]);
                        }),
                    Forms\Components\Select::make('lokasi_id')
                        ->label(fn (Forms\Get $get): string => $get('jenis_mutasi') === 'masuk' ? 'Gudang Tujuan' : 'Gudang Asal')
                        ->options(fn (): array => Lokasi::query()->gudang()->orderBy('nama_lokasi')
                            ->get()->mapWithKeys(fn (Lokasi $item): array => [$item->id => "{$item->nama_lokasi} ({$item->kode_lokasi})"])->all())
                        ->searchable()->preload()->native(false)->required()->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('items', [['kondisi_tujuan' => Mutasi::KONDISI_BAIK]]))
                        ->rules([Rule::exists('lokasis', 'id')->where('jenis_lokasi', Lokasi::JENIS_GUDANG)]),
                    Forms\Components\Select::make('lokasi_tujuan_id')
                        ->label('Lokasi Tujuan')
                        ->options(fn (): array => Lokasi::query()->orderBy('jenis_lokasi')->orderBy('nama_lokasi')
                            ->get()->mapWithKeys(function (Lokasi $item): array {
                                $type = Lokasi::jenisOptions()[$item->jenis_lokasi] ?? 'Lokasi';

                                return [$item->id => "{$item->nama_lokasi} ({$type})"];
                            })->all())
                        ->searchable()->preload()->native(false)->live()
                        ->visible(fn (Forms\Get $get): bool => $get('jenis_mutasi') === 'keluar')
                        ->required(fn (Forms\Get $get): bool => $get('jenis_mutasi') === 'keluar')
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('items', [['kondisi_tujuan' => Mutasi::KONDISI_BAIK]]))
                        ->rules([
                            fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($value && (int) $value === (int) $get('lokasi_id')) {
                                    $fail('Lokasi tujuan tidak boleh sama dengan gudang asal.');
                                }
                            },
                        ]),
                    Forms\Components\Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(fn (): array => Supplier::query()->aktif()->orderBy('nama_supplier')
                            ->pluck('nama_supplier', 'id')->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => Supplier::query()
                            ->whereKey((int) $value)->value('nama_supplier'))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visible(fn (Forms\Get $get): bool => $get('jenis_mutasi') === 'masuk')
                        ->required(fn (Forms\Get $get): bool => $get('jenis_mutasi') === 'masuk')
                        ->rules([Rule::exists('suppliers', 'id')->where('aktif', true)])
                        ->createOptionForm([
                            Forms\Components\TextInput::make('nama_supplier')
                                ->label('Nama Supplier')
                                ->required()
                                ->unique(Supplier::class, 'nama_supplier')
                                ->maxLength(255),
                            Forms\Components\TextInput::make('kontak_person')
                                ->label('Kontak Person')
                                ->maxLength(255),
                            Forms\Components\TextInput::make('telepon')
                                ->label('No. Telepon')
                                ->tel()
                                ->maxLength(50),
                        ])
                        ->createOptionUsing(fn (array $data): int => (int) Supplier::query()
                            ->create([...$data, 'aktif' => true])->getKey())
                        ->helperText('Pilih supplier yang tersedia atau klik tambah untuk membuat supplier baru.'),
                    Forms\Components\TextInput::make('no_ref')->label('No. Referensi')->maxLength(255),
                    Forms\Components\TextInput::make('keterangan')->label('Keterangan')->maxLength(255),
                    Forms\Components\Repeater::make('items')
                        ->label('Daftar Barang')->defaultItems(1)->minItems(1)->reorderable(false)
                        ->addActionLabel('Tambah Barang')->columnSpanFull()->columns(2)
                        ->schema([
                            Forms\Components\Select::make('barang_id')->label('Barang')
                                ->options(fn (Forms\Get $get): array => static::availableBarangOptions(
                                    (int) ($get('../../lokasi_id') ?: 0), $get('../../jenis_mutasi'),
                                ))
                                ->getOptionLabelUsing(fn (mixed $value): ?string => static::barangOptionLabel($value))
                                ->searchable()->preload()->native(false)->live()
                                ->selectablePlaceholder(false)
                                ->required(fn (Forms\Get $get): bool => blank($get('barang_id_terpilih')))
                                ->rules(['nullable', 'exists:barangs,id'])
                                ->validationAttribute('Barang')
                                ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->afterStateUpdated(function (mixed $state, Forms\Get $get, Forms\Set $set): void {
                                    // Choices can emit a temporary null while Livewire rebuilds its
                                    // options. Keep the last deliberate selection in that situation.
                                    if (blank($state)) {
                                        return;
                                    }

                                    $set('barang_id_terpilih', (int) $state);
                                    $set('kondisi_asal', null);
                                    $warehouseId = $get('../../jenis_mutasi') === 'masuk'
                                        ? $get('../../lokasi_id') : $get('../../lokasi_tujuan_id');
                                    $set('posisi_rak_tujuan_id', static::fixedTargetPosition(
                                        (int) $state,
                                        (int) $warehouseId,
                                    ));
                                }),
                            Forms\Components\Hidden::make('barang_id_terpilih')
                                ->dehydrated(),
                            Forms\Components\TextInput::make('jumlah')->label('Jumlah')->integer()->minValue(1)->required()
                                ->suffix(fn (Forms\Get $get): ?string => static::barangUnit(
                                    static::selectedItemBarangId($get),
                                ))
                                ->helperText(function (Forms\Get $get): ?string {
                                    if ($get('../../jenis_mutasi') === 'masuk') {
                                        return null;
                                    }
                                    $condition = $get('kondisi_asal');
                                    if (! $condition) {
                                        return 'Pilih kondisi asal untuk melihat stok tersedia.';
                                    }
                                    $available = static::getStokTersedia(
                                        static::selectedItemBarangId($get), (int) $get('../../lokasi_id'), $condition,
                                    );

                                    return "Stok tersedia setelah dikurangi pending: {$available}";
                                })
                                ->rules([
                                    fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                        if ($get('../../jenis_mutasi') === 'masuk' || ! $get('kondisi_asal')) {
                                            return;
                                        }
                                        $available = static::getStokTersedia(
                                            static::selectedItemBarangId($get),
                                            (int) $get('../../lokasi_id'),
                                            $get('kondisi_asal'),
                                        );
                                        if ((int) $value > $available) {
                                            $fail("Stok kondisi yang dipilih tidak mencukupi. Tersedia: {$available}.");
                                        }
                                    },
                                ]),
                            Forms\Components\Select::make('kondisi_asal')->label('Kondisi Asal')
                                ->options(function (Forms\Get $get): array {
                                    $options = [];
                                    foreach (Mutasi::kondisiOptions() as $value => $label) {
                                        if (static::getStokTersedia(
                                            static::selectedItemBarangId($get),
                                            (int) $get('../../lokasi_id'),
                                            $value,
                                        ) > 0) {
                                            $options[$value] = $label;
                                        }
                                    }

                                    return $options;
                                })->native(false)->required(fn (Forms\Get $get): bool => $get('../../jenis_mutasi') !== 'masuk')
                                ->visible(fn (Forms\Get $get): bool => $get('../../jenis_mutasi') !== 'masuk')->live(),
                            Forms\Components\Select::make('kondisi_tujuan')->label('Kondisi Setelah Mutasi')
                                ->options(Mutasi::kondisiOptions())->default(Mutasi::KONDISI_BAIK)
                                ->native(false)->required()
                                ->rules([
                                    fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                        if ($get('../../jenis_mutasi') === 'perubahan_kondisi' && $value === $get('kondisi_asal')) {
                                            $fail('Kondisi baru harus berbeda dari kondisi asal.');
                                        }
                                    },
                                ]),
                            Forms\Components\Select::make('posisi_rak_tujuan_id')->label('Posisi Rak Tujuan')
                                ->options(function (Forms\Get $get): array {
                                    $warehouseId = $get('../../jenis_mutasi') === 'masuk'
                                        ? $get('../../lokasi_id') : $get('../../lokasi_tujuan_id');

                                    return static::targetPositionOptions(
                                        static::selectedItemBarangId($get),
                                        (int) $warehouseId,
                                    );
                                })->searchable()->preload()->native(false)
                                ->disableOptionWhen(function (string $value, Forms\Get $get): bool {
                                    $warehouseId = $get('../../jenis_mutasi') === 'masuk'
                                        ? $get('../../lokasi_id') : $get('../../lokasi_tujuan_id');
                                    $fixedPositionId = static::fixedTargetPosition(
                                        static::selectedItemBarangId($get),
                                        (int) $warehouseId,
                                    );

                                    return $fixedPositionId && (int) $value !== $fixedPositionId;
                                })
                                ->visible(function (Forms\Get $get): bool {
                                    $warehouseId = $get('../../jenis_mutasi') === 'masuk'
                                        ? $get('../../lokasi_id') : $get('../../lokasi_tujuan_id');

                                    return $get('../../jenis_mutasi') !== 'perubahan_kondisi'
                                        && static::warehouseUsesRacks((int) $warehouseId);
                                })
                                ->required(function (Forms\Get $get): bool {
                                    $warehouseId = $get('../../jenis_mutasi') === 'masuk'
                                        ? $get('../../lokasi_id') : $get('../../lokasi_tujuan_id');

                                    return (bool) static::selectedItemBarangId($get)
                                        && static::warehouseUsesRacks((int) $warehouseId);
                                })
                                ->dehydrated()
                                ->placeholder('Pilih posisi rak')
                                ->helperText(function (Forms\Get $get): string {
                                    $warehouseId = $get('../../jenis_mutasi') === 'masuk'
                                        ? $get('../../lokasi_id') : $get('../../lokasi_tujuan_id');

                                    $fixedPositionId = static::fixedTargetPosition(
                                        static::selectedItemBarangId($get),
                                        (int) $warehouseId,
                                    );

                                    return $fixedPositionId
                                        ? 'Barang sudah memiliki rak tetap. Rak lain tetap ditampilkan tetapi tidak dapat dipilih; gunakan menu Mutasi Antar Rak untuk memindahkannya.'
                                        : 'Wajib dipilih. Penempatan ini menjadi rak tetap barang pada gudang tujuan.';
                                }),
                        ]),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Ringkasan Mutasi')->columns(3)->schema([
                TextEntry::make('id')->label('ID')->formatStateUsing(fn (int $state): string => "#{$state}"),
                TextEntry::make('tanggal')->date('d F Y'),
                TextEntry::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'cancelled' => 'danger', default => 'warning',
                }),
                TextEntry::make('barang.nama_barang')->label('Barang'),
                TextEntry::make('supplier.nama_supplier')->label('Supplier')->placeholder('-'),
                TextEntry::make('jenis_mutasi')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state): string => Mutasi::jenisOptions()[$state] ?? $state),
                TextEntry::make('jumlah')->numeric(),
                TextEntry::make('kondisi_asal')->label('Kondisi Asal')->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => Mutasi::kondisiOptions()[$state] ?? '-'),
                TextEntry::make('kondisi_tujuan')->label('Kondisi Setelah Mutasi')
                    ->formatStateUsing(fn (?string $state): string => Mutasi::kondisiOptions()[$state] ?? '-'),
                TextEntry::make('user.name')->label('Dicatat oleh'),
            ]),
            InfolistSection::make('Lokasi dan Rak')->columns(2)->schema([
                TextEntry::make('lokasi.nama_lokasi')->label('Gudang'),
                TextEntry::make('lokasiTujuan.nama_lokasi')->label('Lokasi Tujuan')->placeholder('-'),
                TextEntry::make('posisiRakAsal.kode')->label('Rak Asal')->placeholder('Tanpa rak'),
                TextEntry::make('posisiRakTujuan.kode')->label('Rak Tujuan')->placeholder('Tanpa rak'),
                TextEntry::make('no_ref')->label('No. Referensi')->placeholder('-'),
                TextEntry::make('keterangan')->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('barang.nama_barang')->label('Barang')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('supplier.nama_supplier')->label('Supplier')->searchable()->placeholder('-'),
                Tables\Columns\TextColumn::make('jenis_mutasi')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state): string => Mutasi::jenisOptions()[$state] ?? $state),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')->label('Gudang')->searchable(),
                Tables\Columns\TextColumn::make('posisiRakAsal.kode')->label('Rak Asal')->placeholder('-'),
                Tables\Columns\TextColumn::make('lokasiTujuan.nama_lokasi')->label('Tujuan')->placeholder('-'),
                Tables\Columns\TextColumn::make('posisiRakTujuan.kode')->label('Rak Tujuan')->placeholder('-'),
                Tables\Columns\TextColumn::make('kondisi_asal')->label('Dari')->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => Mutasi::kondisiOptions()[$state] ?? '-'),
                Tables\Columns\TextColumn::make('kondisi_tujuan')->label('Menjadi')
                    ->formatStateUsing(fn (?string $state): string => Mutasi::kondisiOptions()[$state] ?? '-'),
                Tables\Columns\TextColumn::make('jumlah')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'cancelled' => 'danger', default => 'warning',
                }),
                Tables\Columns\TextColumn::make('user.name')->label('Dicatat oleh')->toggleable(),
            ])
            ->filters([
                Filter::make('rentang_tanggal')->form([
                    Forms\Components\DatePicker::make('from')->label('Dari')->native(false),
                    Forms\Components\DatePicker::make('to')->label('Sampai')->native(false),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('tanggal', '>=', $date))
                    ->when($data['to'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('tanggal', '<=', $date))),
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'approved' => 'Disetujui', 'cancelled' => 'Dibatalkan',
                ]),
                Tables\Filters\SelectFilter::make('jenis_mutasi')->options(Mutasi::jenisOptions()),
            ])
            ->headerActions([
                Action::make('import-excel')->label('Import Excel')->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')->disk('local')->directory('imports/mutasi')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'])
                            ->maxSize(10240)->required(),
                        Forms\Components\Select::make('status_mode')->label('Status Hasil Import')->options([
                            'pending' => 'Pending', 'approved' => 'Langsung disetujui',
                        ])->default('pending')->required()->native(false),
                    ])->action(function (array $data): void {
                        $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                        try {
                            $result = app(MutasiExcelImportService::class)->import(
                                Storage::disk('local')->path($file), (int) auth()->id(), $data['status_mode'] ?? 'pending',
                            );
                            Notification::make()->title('Import mutasi selesai')
                                ->body("Berhasil: {$result['imported']} | Dilewati: {$result['skipped']} | Gagal: {$result['failed']}")
                                ->success()->send();
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Import mutasi gagal')->body($exception->getMessage())->danger()->send();
                        } finally {
                            Storage::disk('local')->delete($file);
                        }
                    }),
                Action::make('export-excel')->label('Export Excel')->icon('heroicon-o-arrow-down-tray')->color('success')
                    ->action(fn ($livewire) => app(MutasiExcelExportService::class)->download(
                        $livewire->getTableQueryForExport(),
                        ['active_tab' => $livewire->activeTab ?? null, 'filters' => $livewire->tableFilters ?? []],
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Mutasi $record): bool => static::canApproveRecord($record))->requiresConfirmation()
                    ->action(function (Mutasi $record): void {
                        try {
                            app(MutasiStockService::class)->approve($record, (int) auth()->id());
                            Notification::make()->title('Mutasi disetujui')->success()->send();
                        } catch (\Throwable $exception) {
                            Notification::make()->title('Gagal approve')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('cancel')->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Mutasi $record): bool => static::isSuperAdmin() && $record->status === 'pending')
                    ->form([Forms\Components\Textarea::make('cancel_reason')->label('Alasan')->required()->maxLength(255)])
                    ->action(function (Mutasi $record, array $data): void {
                        $record->update([
                            'status' => 'cancelled', 'cancelled_by' => auth()->id(),
                            'cancelled_at' => now(), 'cancel_reason' => $data['cancel_reason'],
                        ]);
                        Notification::make()->title('Mutasi dibatalkan')->success()->send();
                    }),
            ], ActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')->label('Approve Terpilih')->icon('heroicon-o-check-circle')
                        ->color('success')->requiresConfirmation()->visible(fn (): bool => static::canApproveAny())
                        ->action(function (Collection $records): void {
                            $approved = 0;
                            $errors = [];
                            foreach ($records as $record) {
                                if ($record->status !== 'pending') {
                                    continue;
                                }
                                try {
                                    app(MutasiStockService::class)->approve($record, (int) auth()->id());
                                    $approved++;
                                } catch (\Throwable $exception) {
                                    $errors[] = "#{$record->id}: {$exception->getMessage()}";
                                }
                            }
                            $notification = Notification::make()->title("{$approved} mutasi disetujui");
                            if ($errors) {
                                $notification->body(implode(' | ', array_slice($errors, 0, 3)))->warning();
                            } else {
                                $notification->success();
                            }
                            $notification->send();
                        })->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMutasis::route('/'),
            'create' => Pages\CreateMutasi::route('/buat'),
            'view' => Pages\ViewMutasi::route('/{record}'),
        ];
    }
}
