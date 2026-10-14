<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiUsageResource\Pages;
use App\Models\AiUsage;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Monitoring pemakaian token Asisten AI (super_admin, read-only).
 */
class AiUsageResource extends Resource
{
    protected static ?string $model = AiUsage::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Penggunaan Token AI';

    protected static ?string $modelLabel = 'Penggunaan Token';

    protected static ?string $pluralModelLabel = 'Penggunaan Token AI';

    protected static ?int $navigationSort = 907;

    protected static ?string $slug = 'asisten-ai-token';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Ringkasan Permintaan')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                    TextEntry::make('user.name')->label('Pengguna')->placeholder('Sistem'),
                    TextEntry::make('model')->label('Model')->badge()->placeholder('-'),
                    TextEntry::make('provider')->label('Provider')->placeholder('-'),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('latency_ms')->label('Latensi')->suffix(' ms'),
                    TextEntry::make('prompt_tokens')->label('Token Prompt')->numeric(),
                    TextEntry::make('completion_tokens')->label('Token Balasan')->numeric(),
                    TextEntry::make('total_tokens')->label('Total Token')->numeric()->weight('bold'),
                    TextEntry::make('tool_calls')->label('Pemanggilan Tool')->numeric(),
                    TextEntry::make('iterations')->label('Iterasi')->numeric(),
                    TextEntry::make('error_message')->label('Pesan Error')->placeholder('-')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Pengguna')
                    ->placeholder('Sistem')
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->label('Model')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('prompt_tokens')
                    ->label('Prompt')
                    ->numeric()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')),
                Tables\Columns\TextColumn::make('completion_tokens')
                    ->label('Balasan')
                    ->numeric()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')),
                Tables\Columns\TextColumn::make('total_tokens')
                    ->label('Total Token')
                    ->numeric()
                    ->weight('bold')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')),
                Tables\Columns\TextColumn::make('tool_calls')
                    ->label('Tool')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('iterations')
                    ->label('Iterasi')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latency_ms')
                    ->label('Latensi (ms)')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === AiUsage::STATUS_SUCCESS ? 'Sukses' : 'Gagal')
                    ->color(fn (string $state): string => $state === AiUsage::STATUS_SUCCESS ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\Filter::make('created_at')
                    ->label('Rentang tanggal')
                    ->form([
                        DatePicker::make('dari')->label('Dari')->native(true),
                        DatePicker::make('sampai')->label('Sampai')->native(true),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['dari'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['sampai'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        AiUsage::STATUS_SUCCESS => 'Sukses',
                        AiUsage::STATUS_ERROR => 'Gagal',
                    ]),
                Tables\Filters\SelectFilter::make('model')
                    ->label('Model')
                    ->options(fn (): array => AiUsage::query()->whereNotNull('model')
                        ->distinct()->pluck('model', 'model')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Detail'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Belum ada pemakaian token')
            ->emptyStateDescription('Pemakaian token akan tercatat otomatis setiap kali asisten dipakai.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiUsages::route('/'),
        ];
    }
}
