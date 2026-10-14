<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiKnowledgeEntryResource\Pages;
use App\Models\AiKnowledgeEntry;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Basis pengetahuan Asisten AI (super_admin).
 *
 * Setiap entri mengarahkan jawaban/tindakan asisten. Entri dengan kata kunci
 * hanya disuntik ke prompt saat relevan (hemat token); entri "selalu sertakan"
 * dipakai untuk aturan/batasan penting.
 */
class AiKnowledgeEntryResource extends Resource
{
    protected static ?string $model = AiKnowledgeEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pengetahuan Asisten AI';

    protected static ?string $modelLabel = 'Pengetahuan AI';

    protected static ?string $pluralModelLabel = 'Pengetahuan Asisten AI';

    protected static ?int $navigationSort = 906;

    protected static ?string $slug = 'asisten-ai-pengetahuan';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Entri Pengetahuan')
                    ->description('Tulis satu pengetahuan per entri agar mudah dikelola.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Jenis')
                            ->options(AiKnowledgeEntry::typeOptions())
                            ->default(AiKnowledgeEntry::TYPE_FAQ)
                            ->required(),
                        Forms\Components\TextInput::make('title')
                            ->label('Judul (opsional)')
                            ->maxLength(255)
                            ->placeholder('Contoh: Satuan galon'),
                        Forms\Components\TagsInput::make('keywords')
                            ->label('Kata kunci pemicu')
                            ->placeholder('Tambah kata kunci lalu Enter')
                            ->helperText('Entri hanya disuntik ke AI bila pesan user mengandung salah satu kata ini. Kosongkan untuk entri global.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('content')
                            ->label('Isi pengetahuan')
                            ->rows(4)
                            ->required()
                            ->maxLength(4000)
                            ->placeholder('Contoh: Galon air dicatat dengan satuan "buah", bukan liter.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('action_hint')
                            ->label('Petunjuk tindakan (opsional)')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Contoh: Gunakan tool cek_stok lalu buat_draft_mutasi.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('priority')
                            ->label('Prioritas')
                            ->numeric()
                            ->default(0)
                            ->helperText('Angka lebih besar tampil lebih dulu.'),
                        Forms\Components\Toggle::make('always_include')
                            ->label('Selalu sertakan')
                            ->helperText('Aktifkan untuk aturan/batasan penting yang harus selalu diketahui AI.'),
                        Forms\Components\Toggle::make('active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiKnowledgeEntry::typeOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        AiKnowledgeEntry::TYPE_RULE => 'warning',
                        AiKnowledgeEntry::TYPE_RESTRICTION => 'danger',
                        AiKnowledgeEntry::TYPE_INSTRUCTION => 'info',
                        AiKnowledgeEntry::TYPE_GLOSSARY => 'gray',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('content')
                    ->label('Isi')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\IconColumn::make('always_include')
                    ->label('Selalu')
                    ->boolean(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritas')
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(AiKnowledgeEntry::typeOptions()),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Status Aktif'),
                Tables\Filters\TernaryFilter::make('always_include')
                    ->label('Selalu sertakan'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada pengetahuan')
            ->emptyStateDescription('Tambahkan aturan, FAQ, atau istilah agar asisten lebih terarah.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiKnowledgeEntries::route('/'),
            'create' => Pages\CreateAiKnowledgeEntry::route('/buat'),
            'edit' => Pages\EditAiKnowledgeEntry::route('/{record}/ubah'),
        ];
    }
}
