<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Sso\UserLinkResolver;
use Illuminate\Console\Command;

/**
 * sso:show-uuid {email}
 *
 * Menampilkan `portal_uuid` milik user Logistik. Developer menyalin UUID ini
 * secara MANUAL ke Portal (sso:link-account / UI admin). Tidak ada auto-match.
 *
 * Bila user belum punya UUID, command ini hanya memberi tahu untuk memakai
 * `sso:assign-uuid` (tidak men-generate diam-diam).
 */
class SsoShowUuidCommand extends Command
{
    protected $signature = 'sso:show-uuid {email : Email atau username user Logistik}';

    protected $description = 'Menampilkan portal_uuid (identitas SSO) milik user Logistik.';

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

        if (blank($user->portal_uuid)) {
            $this->warn("User '{$user->email}' belum memiliki portal_uuid.");
            $this->line('Jalankan: php artisan sso:assign-uuid '.$user->email);

            return self::SUCCESS;
        }

        $this->info('portal_uuid untuk '.$user->email.':');
        $this->line((string) $user->portal_uuid);
        $this->newLine();
        $this->comment('Salin UUID di atas ke Portal (manual), contoh:');
        $this->line('php artisan sso:link-account --portal=<username-portal> --app=logistik --uuid='.$user->portal_uuid);

        return self::SUCCESS;
    }
}
