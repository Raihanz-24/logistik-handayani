<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    // Sidebar
    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 9999; // makin besar = makin bawah

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Pengguna';

    public static function getModelLabel(): string
    {
        return 'Pengguna';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pengguna';
    }

    /**
     * Kalau return string => user TIDAK boleh dihapus, dan string tsb akan jadi pesan modal.
     * Kalau return null => user boleh dihapus.
     */
    public static function cannotDeleteReason(User $user): ?string
    {
        // Opsional: cegah hapus diri sendiri
        if (auth()->check() && $user->id === auth()->id()) {
            return 'Akun yang sedang dipakai login tidak dapat dihapus.';
        }

        // Cek riwayat mutasi
        $hasMutasi =
            $user->mutasi()->exists() ||
            $user->mutasiDibuat()->exists();

        if ($hasMutasi) {
            return 'User tidak dapat di hapus karena memiliki riwayat mutasi, silahkan hubungi developer jika diperlukan.';
        }

        return null;
    }

    public static function form(Form $form): Form
    {
        $guardName = config('auth.defaults.guard', 'web');

        return $form
            ->schema([
                Section::make('Data Pengguna')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('username')
                            ->label('Username')
                            ->required()
                            ->minLength(3)
                            ->maxLength(50)
                            ->regex('/^[a-zA-Z0-9._-]+$/')
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (string $state): string => strtolower(trim($state)))
                            ->helperText('Digunakan untuk login. Boleh memakai huruf, angka, titik, garis bawah, dan tanda minus.'),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->confirmed() // butuh field password_confirmation
                            ->rules([Password::min(8)->numbers()->symbols()])
                            ->required(fn (string $operation) => $operation === 'create')
                            // edit: jangan overwrite password kalau kosong
                            ->dehydrated(fn ($state) => filled($state)),

                        TextInput::make('password_confirmation')
                            ->label('Konfirmasi Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->required(fn (string $operation) => $operation === 'create'),
                    ]),

                Section::make('Role Akses')
                    ->description('Pengguna wajib punya minimal 1 role agar bisa akses panel.')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query->where('guard_name', $guardName),
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->helperText("Guard: {$guardName}"),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(', ')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),

                // Delete tetap clickable, tapi kalau tidak boleh hapus -> modal info
                DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation()
                    ->modalHeading(function (User $record): string {
                        return static::cannotDeleteReason($record)
                            ? 'Pengguna tidak dapat dihapus'
                            : 'Hapus pengguna';
                    })
                    ->modalDescription(function (User $record): string {
                        return static::cannotDeleteReason($record)
                            ?: 'Apakah Anda yakin ingin menghapus pengguna ini?';
                    })
                    ->modalSubmitActionLabel(function (User $record): string {
                        return static::cannotDeleteReason($record) ? 'Tutup' : 'Hapus';
                    })
                    ->modalCancelActionLabel('Batal')
                    ->action(function (User $record): void {
                        $reason = static::cannotDeleteReason($record);

                        // Tidak menghapus, hanya tampilkan modal info (karena requiresConfirmation sudah memunculkan modal)
                        if ($reason) {
                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Berhasil')
                            ->body('Pengguna berhasil dihapus.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // Bulk delete: aman — skip yang tidak boleh dihapus
                    DeleteBulkAction::make()
                        ->label('Hapus terpilih')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $user) {
                                $reason = static::cannotDeleteReason($user);

                                if ($reason) {
                                    $skipped++;

                                    continue;
                                }

                                $user->delete();
                                $deleted++;
                            }

                            Notification::make()
                                ->title('Selesai')
                                ->body("Berhasil hapus: {$deleted}. Dilewati: {$skipped}.")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
