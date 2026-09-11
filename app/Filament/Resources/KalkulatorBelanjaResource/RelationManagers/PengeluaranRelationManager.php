<?php

namespace App\Filament\Resources\KalkulatorBelanjaResource\RelationManagers;

use App\Filament\Pages\FotoBarangMaps;
use App\Models\Barang;
use App\Models\FotoBarangSession;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BelanjaTransactionService;
use App\Services\FotoBarangPurchaseItemLinkService;
use App\Services\NotaBelanjaImageService;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class PengeluaranRelationManager extends RelationManager
{
    private const MAX_ORIGINAL_RECEIPT_SIZE_KB = 10 * 1024;

    protected static string $relationship = 'pengeluaran';

    protected static ?string $title = 'Transaksi per Toko';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Detail Belanja per Toko')
            ->description('Satu toko dan satu nota dihitung sebagai satu transaksi. Total selalu dihitung ulang oleh server.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'supplier',
                'items.barang',
                'items.photoLinks.photo.session',
                'notas',
                'fotoBarangSessions',
            ]))
            ->defaultSort('urutan')
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->columns([
                Tables\Columns\ViewColumn::make('transaction_card')
                    ->label('Transaksi')
                    ->view('filament.tables.columns.pengeluaran-belanja-card'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make('tambah-transaksi')
                    ->label('Tambah Transaksi Toko')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Tambah transaksi toko')
                    ->modalDescription('Pilih barang dari master data. Harga terakhir hanya saran dan tetap dapat diubah.')
                    ->modalWidth('7xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->createAnother(false)
                    ->form($this->transactionForm())
                    ->using(fn (array $data): Model => app(BelanjaTransactionService::class)->save(
                        $this->ownerSession(),
                        null,
                        $data,
                    ))
                    ->after(fn () => $this->dispatch('belanja-updated')),
            ])
            ->actions([
                Tables\Actions\EditAction::make('ubah-transaksi')
                    ->label('Ubah')
                    ->modalHeading('Ubah transaksi toko')
                    ->modalDescription('Subtotal dan total diperbarui otomatis. Jika detail barang dihapus, label Foto Maps hanya dilepas—foto tetap aman.')
                    ->modalWidth('7xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->form($this->transactionForm())
                    ->mutateRecordDataUsing(fn (array $data, PengeluaranBelanja $record): array => [
                        ...$data,
                        'items' => $record->items->map(fn ($item): array => [
                            'barang_id' => $item->barang_id,
                            'satuan' => $item->satuan_snapshot,
                            'jumlah' => $item->jumlah,
                            // Rp0 adalah penanda internal untuk harga yang belum
                            // diisi. Di form tetap tampil kosong agar mudah diisi
                            // setelah daftar barang selesai dibuat.
                            'harga_satuan' => (int) $item->harga_satuan > 0 ? $item->harga_satuan : null,
                            'keterangan' => $item->keterangan,
                        ])->values()->all(),
                        'nota_paths' => $record->notas->pluck('path')->all(),
                    ])
                    ->using(fn (array $data, Model $record): Model => app(BelanjaTransactionService::class)->save(
                        $this->ownerSession(),
                        $record instanceof PengeluaranBelanja ? $record : null,
                        $data,
                    ))
                    ->after(fn () => $this->dispatch('belanja-updated')),
                Tables\Actions\Action::make('atur-foto-maps')
                    ->label('Folder Foto Maps')
                    ->icon('heroicon-m-camera')
                    ->color('info')
                    ->modalHeading('Hubungkan folder Foto Maps')
                    ->modalDescription('Opsional. Melepas hubungan tidak menghapus folder maupun fotonya.')
                    ->fillForm(fn (PengeluaranBelanja $record): array => [
                        'folder_ids' => $record->fotoBarangSessions()->pluck('foto_barang_sessions.id')->all(),
                    ])
                    ->form([
                        Forms\Components\Select::make('folder_ids')
                            ->label('Folder terkait')
                            ->options(fn (): array => $this->folderOptions())
                            ->multiple()
                            ->maxItems(30)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->helperText('Boleh dikosongkan jika transaksi ini tidak memiliki foto barang.'),
                    ])
                    ->action(function (PengeluaranBelanja $record, array $data): void {
                        $requestedIds = collect($data['folder_ids'] ?? [])
                            ->filter(fn (mixed $id): bool => is_numeric($id))
                            ->map(fn (mixed $id): int => (int) $id)
                            ->filter(fn (int $id): bool => $id > 0)
                            ->unique()
                            ->take(30)
                            ->values();
                        $validIds = $this->visibleFoldersQuery()
                            ->whereKey($requestedIds->all())
                            ->pluck('id');

                        abort_if($validIds->count() !== $requestedIds->count(), 403);

                        $before = $record->fotoBarangSessions()->pluck('foto_barang_sessions.id')->all();
                        $record->fotoBarangSessions()->sync($validIds->all());

                        if (Schema::hasTable('foto_barang_item_belanja_links')) {
                            app(FotoBarangPurchaseItemLinkService::class)->pruneSessions([
                                ...$before,
                                ...$validIds->all(),
                            ]);
                        }

                        app(AuditLogger::class)->activity(
                            'pengeluaran_belanja_foto_maps_sync',
                            'Mengatur folder Foto Maps transaksi: '.$record->namaSupplier(),
                            auth()->user(),
                            [
                                'pengeluaran_belanja_id' => $record->getKey(),
                                'folder_sebelum' => $before,
                                'folder_sesudah' => $validIds->all(),
                            ],
                        );

                        Notification::make()
                            ->title('Hubungan folder Foto Maps disimpan')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('buka-foto-maps')
                    ->label('Buat Folder Foto Maps')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (PengeluaranBelanja $record): string => FotoBarangMaps::getUrl([
                        'pengeluaran' => $record->getKey(),
                    ])),
                Tables\Actions\DeleteAction::make('hapus-transaksi')
                    ->label('Hapus')
                    ->modalHeading('Hapus transaksi toko?')
                    ->modalDescription('Detail barang dan foto nota transaksi ini akan dihapus. Folder Foto Maps hanya dilepas, tidak ikut dihapus.')
                    ->using(fn (Model $record): bool => $record instanceof PengeluaranBelanja
                        && app(BelanjaTransactionService::class)->delete($record))
                    ->after(fn () => $this->dispatch('belanja-updated')),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->paginationPageOptions([6, 12, 24])
            ->defaultPaginationPageOption(6)
            ->emptyStateHeading('Belum ada transaksi toko')
            ->emptyStateDescription('Tekan Tambah Transaksi Toko untuk mencatat barang, harga, nota, dan keterangannya.');
    }

    /** @return array<int, Forms\Components\Component> */
    private function transactionForm(): array
    {
        return [
            Section::make('Toko dan Catatan')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\Select::make('supplier_id')
                        ->label('Toko / Supplier')
                        ->options(fn (): array => Supplier::query()->aktif()->orderBy('nama_supplier')
                            ->pluck('nama_supplier', 'id')->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => Supplier::query()
                            ->whereKey((int) $value)->value('nama_supplier'))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            $items = $get('items');

                            if (! is_array($items)) {
                                return;
                            }

                            foreach ($items as &$item) {
                                if (! is_array($item) || ! filled($item['barang_id'] ?? null)) {
                                    continue;
                                }

                                $latest = $this->suggestedPrice(
                                    (int) $state,
                                    (int) $item['barang_id'],
                                    $item['satuan'] ?? null,
                                );
                                if (blank($item['harga_satuan'] ?? null) && $latest['price'] !== null) {
                                    $item['harga_satuan'] = $latest['price'];
                                }
                            }
                            unset($item);

                            $set('items', $items);
                        })
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
                        ->required(),
                    Forms\Components\Textarea::make('keterangan')
                        ->label('Keterangan Transaksi')
                        ->placeholder('Contoh: belanja kebutuhan dapur mingguan (opsional)')
                        ->rows(2)
                        ->maxLength(255),
                ]),

            Section::make('Daftar Barang')
                ->description('Jumlah boleh pecahan hingga 3 angka desimal. Satu barang hanya satu kali pada transaksi toko yang sama.')
                ->schema([
                    Repeater::make('items')
                        ->label('')
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(100)
                        ->reorderable()
                        ->collapsible()
                        ->addActionLabel('Tambah Barang')
                        ->itemLabel(function (array $state): string {
                            $name = filled($state['barang_id'] ?? null)
                                ? Barang::query()->whereKey((int) $state['barang_id'])->value('nama_barang')
                                : null;
                            $subtotal = app(BelanjaTransactionService::class)->subtotal(
                                $state['jumlah'] ?? 0,
                                (int) ($state['harga_satuan'] ?? 0),
                            );

                            return ($name ?: 'Barang baru').($subtotal > 0 ? ' · '.self::rupiah($subtotal) : '');
                        })
                        ->schema([
                            Forms\Components\Select::make('barang_id')
                                ->label('Barang')
                                ->options(fn (): array => Barang::query()
                                    ->orderBy('nama_barang')
                                    ->get(['id', 'kode_barang', 'nama_barang'])
                                    ->mapWithKeys(fn (Barang $barang): array => [
                                        $barang->getKey() => "{$barang->kode_barang} — {$barang->nama_barang}",
                                    ])->all())
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->live()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    $context = app(BelanjaTransactionService::class)->purchaseItemContext((int) $state);
                                    $set('satuan', $context['market'] ? null : $context['unit']);
                                    $latest = $this->suggestedPrice(
                                        (int) $get('../../supplier_id'),
                                        (int) $state,
                                        $context['market'] ? null : $context['unit'],
                                    );
                                    if (blank($get('harga_satuan'))) {
                                        $set('harga_satuan', $latest['price']);
                                    }
                                })
                                ->required()
                                ->columnSpan(['default' => 1, 'lg' => 4]),
                            Forms\Components\TextInput::make('jumlah')
                                ->label('Jumlah')
                                ->numeric()
                                ->step(0.001)
                                ->minValue(0.001)
                                ->maxValue(999999999.999)
                                ->inputMode('decimal')
                                ->live(onBlur: true)
                                ->required()
                                ->columnSpan(['default' => 1, 'lg' => 2]),
                            Forms\Components\Select::make('satuan')
                                ->label('Satuan')
                                ->options(function (Get $get): array {
                                    $context = app(BelanjaTransactionService::class)->purchaseItemContext(
                                        (int) $get('barang_id'),
                                    );

                                    return $context['market']
                                        ? array_combine($context['units'], $context['units']) ?: []
                                        : [];
                                })
                                ->native(false)
                                ->live()
                                ->visible(fn (Get $get): bool => app(BelanjaTransactionService::class)
                                    ->purchaseItemContext((int) $get('barang_id'))['market'])
                                ->required(fn (Get $get): bool => app(BelanjaTransactionService::class)
                                    ->purchaseItemContext((int) $get('barang_id'))['market'])
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    $latest = app(BelanjaTransactionService::class)->latestPriceForUnit(
                                        (int) $get('../../supplier_id'),
                                        (int) $get('barang_id'),
                                        is_string($state) ? $state : null,
                                    );
                                    if (blank($get('harga_satuan'))) {
                                        $set('harga_satuan', $latest['price']);
                                    }
                                })
                                ->helperText('Satuan pasar dapat dipilih sesuai pembelian.')
                                ->columnSpan(['default' => 1, 'lg' => 1]),
                            Forms\Components\Placeholder::make('satuan_preview')
                                ->label('Satuan')
                                ->content(fn (Get $get): string => (string) (Barang::query()
                                    ->whereKey((int) $get('barang_id'))
                                    ->value('satuan') ?: '-'))
                                ->visible(fn (Get $get): bool => ! app(BelanjaTransactionService::class)
                                    ->purchaseItemContext((int) $get('barang_id'))['market'])
                                ->columnSpan(['default' => 1, 'lg' => 1]),
                            Forms\Components\TextInput::make('harga_satuan')
                                ->label('Harga Satuan')
                                ->prefix('Rp')
                                ->type('text')
                                ->numeric()
                                ->integer()
                                ->stripCharacters(['.', ',', ' ', 'Rp', 'rp'])
                                ->minValue(0)
                                ->maxValue(999_999_999_999)
                                ->inputMode('numeric')
                                ->live(onBlur: true)
                                ->helperText(function (Get $get): string {
                                    $history = $this->priceHistoryText(
                                        (int) $get('../../supplier_id'),
                                        (int) $get('barang_id'),
                                    );

                                    return $history !== ''
                                        ? $history
                                        : 'Boleh dikosongkan dahulu. Total akan Rp0 sampai harga diisi.';

                                })
                                ->columnSpan(['default' => 1, 'lg' => 2]),
                            Forms\Components\Placeholder::make('subtotal_preview')
                                ->label('Subtotal')
                                ->content(fn (Get $get): string => self::rupiah(
                                    app(BelanjaTransactionService::class)->subtotal(
                                        $get('jumlah') ?? 0,
                                        (int) ($get('harga_satuan') ?? 0),
                                    ),
                                ))
                                ->extraAttributes(['class' => 'wm-kb-item-subtotal'])
                                ->columnSpan(['default' => 1, 'lg' => 2]),
                            Forms\Components\TextInput::make('keterangan')
                                ->label('Catatan Barang')
                                ->placeholder('Opsional')
                                ->maxLength(500)
                                ->columnSpan(['default' => 1, 'lg' => 3]),
                        ])
                        ->columns(['default' => 1, 'lg' => 9])
                        ->required(),
                    Forms\Components\Placeholder::make('total_preview')
                        ->label('Total Transaksi')
                        ->content(function (Get $get): string {
                            $total = collect($get('items') ?? [])->sum(fn (mixed $item): int => is_array($item)
                                ? app(BelanjaTransactionService::class)->subtotal(
                                    $item['jumlah'] ?? 0,
                                    (int) ($item['harga_satuan'] ?? 0),
                                )
                                : 0);

                            return self::rupiah((int) $total);
                        })
                        ->extraAttributes(['class' => 'wm-kb-transaction-total']),
                ]),

            Section::make('Foto Nota')
                ->description('Opsional. Unggah sampai 20 foto jika nota panjang. Setiap foto otomatis dikompres ke WebP.')
                ->schema([
                    FileUpload::make('nota_paths')
                        ->label('Lampiran Nota')
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(20)
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(self::MAX_ORIGINAL_RECEIPT_SIZE_KB)
                        ->disk('public')
                        ->directory('nota-belanja')
                        ->visibility('public')
                        ->imagePreviewHeight('160')
                        ->openable()
                        ->downloadable()
                        ->saveUploadedFileUsing(
                            fn (TemporaryUploadedFile $file): string => app(NotaBelanjaImageService::class)->store($file),
                        )
                        ->deleteUploadedFileUsing(fn (): bool => true)
                        ->helperText('JPG, PNG, atau WebP · maksimal 10 MB per foto · maksimal 20 foto.'),
                ]),
        ];
    }

    /** @return array{price: ?int, date: ?string} */
    private function suggestedPrice(int $supplierId, int $barangId, ?string $unit): array
    {
        $service = app(BelanjaTransactionService::class);
        $context = $service->purchaseItemContext($barangId);

        return $context['market']
            ? $service->latestPriceForUnit($supplierId, $barangId, $unit)
            : $service->latestPrice($supplierId, $barangId);
    }

    private function priceHistoryText(int $supplierId, int $barangId): string
    {
        $service = app(BelanjaTransactionService::class);
        $context = $service->purchaseItemContext($barangId);

        if ($context['market']) {
            $history = $service->priceHistory($supplierId, $barangId);

            if ($history === []) {
                return 'Belum ada harga terakhir pada supplier ini.';
            }

            return 'Harga terakhir: '.collect($history)
                ->map(fn (array $item): string => self::rupiah($item['price']).' / '.$item['unit'])
                ->implode(' · ');
        }

        $latest = $service->latestPrice($supplierId, $barangId);

        if ($latest['price'] === null) {
            return 'Belum ada riwayat harga pada supplier ini.';
        }

        $date = Carbon::parse($latest['date'])->translatedFormat('d M Y');

        return 'Harga terakhir '.self::rupiah($latest['price']).' · '.$date;
    }

    private function ownerSession(): KalkulatorBelanja
    {
        $record = $this->getOwnerRecord();

        abort_unless($record instanceof KalkulatorBelanja, 404);

        return $record;
    }

    /** @return array<int, string> */
    private function folderOptions(): array
    {
        return $this->visibleFoldersQuery()
            ->withCount('items')
            ->latest('dimulai_at')
            ->limit(250)
            ->get()
            ->mapWithKeys(fn (FotoBarangSession $folder): array => [
                $folder->getKey() => $folder->code().' — '.$folder->judul.' ('.$folder->items_count.' foto)',
            ])
            ->all();
    }

    private function visibleFoldersQuery(): Builder
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return FotoBarangSession::query()->visibleTo($user);
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
