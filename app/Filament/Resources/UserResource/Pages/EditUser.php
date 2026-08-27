<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<int, int> */
    private array $originalRoleIds = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->originalRoleIds = $this->getRecord()->roles()
            ->pluck('roles.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        app(AuditLogger::class)->userCrud('read', $this->getRecord(), auth()->user());
    }

    protected function beforeSave(): void
    {
        $selectedRoleIds = collect($this->data['roles'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($this->getRecord()->is(auth()->user()) && $selectedRoleIds !== $this->originalRoleIds) {
            Notification::make()
                ->title('Role akun sendiri tidak dapat diubah')
                ->body('Gunakan akun superadmin lain untuk mengubah role akun ini.')
                ->danger()
                ->send();

            $this->halt();
        }

        $superAdminRoleId = Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->value('id');

        if (
            $superAdminRoleId &&
            $this->getRecord()->hasRole('super_admin') &&
            ! in_array((int) $superAdminRoleId, $selectedRoleIds, true) &&
            User::role('super_admin')->count() <= 1
        ) {
            Notification::make()
                ->title('Superadmin terakhir tidak dapat diturunkan')
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $currentRoleIds = $this->getRecord()->roles()
            ->pluck('roles.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($currentRoleIds === $this->originalRoleIds) {
            return;
        }

        app(AuditLogger::class)->userCrud('roles_update', $this->getRecord(), auth()->user(), [
            'roles_before' => Role::query()->whereKey($this->originalRoleIds)->pluck('name')->values()->all(),
            'roles_after' => $this->getRecord()->getRoleNames()->values()->all(),
        ]);

        $this->originalRoleIds = $currentRoleIds;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Hapus')
                ->requiresConfirmation()
                ->modalHeading(function (): string {
                    return UserResource::cannotDeleteReason($this->getRecord())
                        ? 'Pengguna tidak dapat dihapus'
                        : 'Hapus pengguna';
                })
                ->modalDescription(function (): string {
                    return UserResource::cannotDeleteReason($this->getRecord())
                        ?: 'Apakah Anda yakin ingin menghapus pengguna ini?';
                })
                ->modalSubmitActionLabel(function (): string {
                    return UserResource::cannotDeleteReason($this->getRecord()) ? 'Tutup' : 'Hapus';
                })
                ->modalCancelActionLabel('Batal')
                ->action(function (): void {
                    $record = $this->getRecord();
                    $reason = UserResource::cannotDeleteReason($record);

                    // kalau tidak boleh hapus, cukup tampilkan modal info
                    if ($reason) {
                        return;
                    }

                    $record->delete();
                }),
        ];
    }
}
