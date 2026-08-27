<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Log Audit';

    protected static ?string $modelLabel = 'Log Audit';

    protected static ?string $pluralModelLabel = 'Log Audit';

    protected static ?int $navigationSort = 900;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Aktivitas')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('user_name')->label('Pengguna')->placeholder('Sistem'),
                        TextEntry::make('user_email')->label('Email')->placeholder('-'),
                        TextEntry::make('event')->label('Jenis')->badge(),
                        TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                        TextEntry::make('description')->label('Aktivitas')->columnSpanFull(),
                        TextEntry::make('method')->label('Metode')->badge()->placeholder('-'),
                        TextEntry::make('status_code')->label('Status HTTP')->placeholder('-'),
                        TextEntry::make('route_name')->label('Nama Route')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('path')->label('Path')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('ip_address')->label('Alamat IP')->placeholder('-'),
                        TextEntry::make('user_agent')->label('Perangkat / Browser')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('metadata_json')
                            ->label('Metadata Aman')
                            ->state(fn (AuditLog $record): string => filled($record->metadata)
                                ? (json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '-')
                                : '-')
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->columnSpanFull(),
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
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_name')
                    ->label('Pengguna')
                    ->description(fn (AuditLog $record): ?string => $record->user_email)
                    ->searchable(['user_name', 'user_email']),
                Tables\Columns\TextColumn::make('event')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'login' => 'Login',
                        'login_api' => 'Login API',
                        'logout' => 'Logout',
                        'user_create' => 'User Dibuat',
                        'user_read', 'user_read_list', 'user_read_history' => 'User Dilihat',
                        'user_update' => 'User Diubah',
                        'user_roles_update' => 'Role Diubah',
                        'user_delete' => 'User Dihapus',
                        default => 'Aktivitas',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'login', 'login_api' => 'success',
                        'logout' => 'warning',
                        'user_create' => 'success',
                        'user_update', 'user_roles_update' => 'warning',
                        'user_delete' => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('description')
                    ->label('Aktivitas')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status_code')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 300 => 'info',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Jenis Aktivitas')
                    ->options([
                        'request' => 'Aktivitas',
                        'login' => 'Login',
                        'login_api' => 'Login API',
                        'logout' => 'Logout',
                        'user_create' => 'User Dibuat',
                        'user_read' => 'User Dilihat',
                        'user_read_list' => 'Daftar User Dilihat',
                        'user_read_history' => 'Riwayat User Dilihat',
                        'user_update' => 'User Diubah',
                        'user_roles_update' => 'Role Diubah',
                        'user_delete' => 'User Dihapus',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Detail'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Belum ada aktivitas tercatat')
            ->emptyStateDescription('Aktivitas pengguna akan muncul otomatis di halaman ini.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
