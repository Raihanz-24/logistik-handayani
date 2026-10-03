<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Sso\UserLinkResolver;
use Illuminate\Console\Command;

/**
 * sso:assign-uuid {email}
 *
 * Membuat & menyimpan `portal_uuid` untuk user Logistik (bila belum ada).
 * IDEMPOTENT: bila sudah ada, tidak mengubah apa pun.
 *
 * UUID di-generate di sisi Logistik karena Logistik adalah "pemilik" mapping
 * `portal_uuid → users.id` (ADR-013, Opsi B).
 */
class SsoAssignUuidCommand extends Command
{
    protected $signature = 'sso:assign-uuid {email : Email atau username user Logistik}';

    protected $description = 'Membuat portal_uuid (identitas SSO) untuk user Logistik (idempotent).';

    public function handle(UserLinkResolver $resolver): int
    {
        $needle = (string) $this->argument('email');

        $user = User::query()
            ->where('email', $needle)
            ->orWhere('username', strtolower(trim($needle)))
            ->first();

        if ($user === null) {
            $this->error("User tidak ditemukan: {$needle}");

            return self::FAILURE;
        }

        $alreadyHad = filled($user->portal_uuid);
        $uuid = $resolver->ensureUuid($user);

        if ($alreadyHad) {
            $this->info("User '{$user->email}' sudah memiliki portal_uuid (tidak diubah):");
        } else {
            $this->info("portal_uuid dibuat untuk '{$user->email}':");
        }

        $this->line($uuid);
        $this->newLine();
        $this->comment('Salin UUID di atas ke Portal (manual), contoh:');
        $this->line('php artisan sso:link-account --portal=<username-portal> --app=logistik --uuid='.$uuid);

        return self::SUCCESS;
    }
}
