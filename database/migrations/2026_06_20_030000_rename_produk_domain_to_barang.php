<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('barangs') && ! Schema::hasTable('produks')) {
            $this->renamePermissions('produk', 'barang');

            return;
        }

        DB::statement('ALTER TABLE `mutasis` DROP FOREIGN KEY `mutasis_produk_id_foreign`');
        DB::statement('ALTER TABLE `produk_lokasi` DROP FOREIGN KEY `produk_lokasi_produk_id_foreign`');
        DB::statement('ALTER TABLE `produk_lokasi` DROP FOREIGN KEY `produk_lokasi_lokasi_id_foreign`');
        DB::statement('ALTER TABLE `kategori_produk_produk` DROP FOREIGN KEY `kategori_produk_produk_kategori_produk_id_foreign`');
        DB::statement('ALTER TABLE `kategori_produk_produk` DROP FOREIGN KEY `kategori_produk_produk_produk_id_foreign`');
        DB::statement('ALTER TABLE `produks` DROP FOREIGN KEY `produks_kategori_produk_id_foreign`');

        Schema::rename('kategori_produks', 'kategori_barangs');
        Schema::rename('produks', 'barangs');
        Schema::rename('produk_lokasi', 'barang_lokasi');
        Schema::rename('kategori_produk_produk', 'barang_kategori_barang');

        DB::statement('ALTER TABLE `barangs` CHANGE `nama_produk` `nama_barang` VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE `barangs` CHANGE `kode_produk` `kode_barang` VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE `barangs` CHANGE `kategori_produk_id` `kategori_barang_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `barangs` RENAME INDEX `produks_kode_produk_unique` TO `barangs_kode_barang_unique`');
        DB::statement('ALTER TABLE `barangs` RENAME INDEX `produks_kategori_produk_id_foreign` TO `barangs_kategori_barang_id_foreign`');

        DB::statement('ALTER TABLE `kategori_barangs` RENAME INDEX `kategori_produks_nama_unique` TO `kategori_barangs_nama_unique`');
        DB::statement('ALTER TABLE `kategori_barangs` RENAME INDEX `kategori_produks_slug_unique` TO `kategori_barangs_slug_unique`');

        DB::statement('ALTER TABLE `barang_lokasi` CHANGE `produk_id` `barang_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `barang_lokasi` RENAME INDEX `produk_lokasi_lokasi_id_foreign` TO `barang_lokasi_lokasi_id_foreign`');

        DB::statement('ALTER TABLE `barang_kategori_barang` CHANGE `kategori_produk_id` `kategori_barang_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `barang_kategori_barang` CHANGE `produk_id` `barang_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `barang_kategori_barang` RENAME INDEX `kategori_produk_produk_produk_id_foreign` TO `barang_kategori_barang_barang_id_foreign`');

        DB::statement('ALTER TABLE `mutasis` CHANGE `produk_id` `barang_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `mutasis` RENAME INDEX `mutasis_produk_id_foreign` TO `mutasis_barang_id_foreign`');

        DB::statement('ALTER TABLE `barangs` ADD CONSTRAINT `barangs_kategori_barang_id_foreign` FOREIGN KEY (`kategori_barang_id`) REFERENCES `kategori_barangs` (`id`) ON DELETE SET NULL');
        DB::statement('ALTER TABLE `barang_lokasi` ADD CONSTRAINT `barang_lokasi_barang_id_foreign` FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `barang_lokasi` ADD CONSTRAINT `barang_lokasi_lokasi_id_foreign` FOREIGN KEY (`lokasi_id`) REFERENCES `lokasis` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `barang_kategori_barang` ADD CONSTRAINT `barang_kategori_barang_kategori_barang_id_foreign` FOREIGN KEY (`kategori_barang_id`) REFERENCES `kategori_barangs` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `barang_kategori_barang` ADD CONSTRAINT `barang_kategori_barang_barang_id_foreign` FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `mutasis` ADD CONSTRAINT `mutasis_barang_id_foreign` FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`)');

        $this->renamePermissions('produk', 'barang');
    }

    public function down(): void
    {
        if (Schema::hasTable('produks') && ! Schema::hasTable('barangs')) {
            $this->renamePermissions('barang', 'produk');

            return;
        }

        DB::statement('ALTER TABLE `mutasis` DROP FOREIGN KEY `mutasis_barang_id_foreign`');
        DB::statement('ALTER TABLE `barang_lokasi` DROP FOREIGN KEY `barang_lokasi_barang_id_foreign`');
        DB::statement('ALTER TABLE `barang_lokasi` DROP FOREIGN KEY `barang_lokasi_lokasi_id_foreign`');
        DB::statement('ALTER TABLE `barang_kategori_barang` DROP FOREIGN KEY `barang_kategori_barang_kategori_barang_id_foreign`');
        DB::statement('ALTER TABLE `barang_kategori_barang` DROP FOREIGN KEY `barang_kategori_barang_barang_id_foreign`');
        DB::statement('ALTER TABLE `barangs` DROP FOREIGN KEY `barangs_kategori_barang_id_foreign`');

        DB::statement('ALTER TABLE `mutasis` CHANGE `barang_id` `produk_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `mutasis` RENAME INDEX `mutasis_barang_id_foreign` TO `mutasis_produk_id_foreign`');

        DB::statement('ALTER TABLE `barang_kategori_barang` CHANGE `kategori_barang_id` `kategori_produk_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `barang_kategori_barang` CHANGE `barang_id` `produk_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `barang_kategori_barang` RENAME INDEX `barang_kategori_barang_barang_id_foreign` TO `kategori_produk_produk_produk_id_foreign`');

        DB::statement('ALTER TABLE `barang_lokasi` CHANGE `barang_id` `produk_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `barang_lokasi` RENAME INDEX `barang_lokasi_lokasi_id_foreign` TO `produk_lokasi_lokasi_id_foreign`');

        DB::statement('ALTER TABLE `barangs` CHANGE `nama_barang` `nama_produk` VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE `barangs` CHANGE `kode_barang` `kode_produk` VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE `barangs` CHANGE `kategori_barang_id` `kategori_produk_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `barangs` RENAME INDEX `barangs_kode_barang_unique` TO `produks_kode_produk_unique`');
        DB::statement('ALTER TABLE `barangs` RENAME INDEX `barangs_kategori_barang_id_foreign` TO `produks_kategori_produk_id_foreign`');

        DB::statement('ALTER TABLE `kategori_barangs` RENAME INDEX `kategori_barangs_nama_unique` TO `kategori_produks_nama_unique`');
        DB::statement('ALTER TABLE `kategori_barangs` RENAME INDEX `kategori_barangs_slug_unique` TO `kategori_produks_slug_unique`');

        Schema::rename('barang_kategori_barang', 'kategori_produk_produk');
        Schema::rename('barang_lokasi', 'produk_lokasi');
        Schema::rename('barangs', 'produks');
        Schema::rename('kategori_barangs', 'kategori_produks');

        DB::statement('ALTER TABLE `produks` ADD CONSTRAINT `produks_kategori_produk_id_foreign` FOREIGN KEY (`kategori_produk_id`) REFERENCES `kategori_produks` (`id`) ON DELETE SET NULL');
        DB::statement('ALTER TABLE `produk_lokasi` ADD CONSTRAINT `produk_lokasi_produk_id_foreign` FOREIGN KEY (`produk_id`) REFERENCES `produks` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `produk_lokasi` ADD CONSTRAINT `produk_lokasi_lokasi_id_foreign` FOREIGN KEY (`lokasi_id`) REFERENCES `lokasis` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `kategori_produk_produk` ADD CONSTRAINT `kategori_produk_produk_kategori_produk_id_foreign` FOREIGN KEY (`kategori_produk_id`) REFERENCES `kategori_produks` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `kategori_produk_produk` ADD CONSTRAINT `kategori_produk_produk_produk_id_foreign` FOREIGN KEY (`produk_id`) REFERENCES `produks` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `mutasis` ADD CONSTRAINT `mutasis_produk_id_foreign` FOREIGN KEY (`produk_id`) REFERENCES `produks` (`id`)');

        $this->renamePermissions('barang', 'produk');
    }

    private function renamePermissions(string $from, string $to): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('name', 'like', "%{$from}%")
            ->orderBy('id')
            ->get()
            ->each(function (object $permission) use ($from, $to): void {
                $newName = str_replace($from, $to, $permission->name);
                $existingId = DB::table('permissions')
                    ->where('name', $newName)
                    ->where('guard_name', $permission->guard_name)
                    ->value('id');

                if (! $existingId) {
                    DB::table('permissions')
                        ->where('id', $permission->id)
                        ->update(['name' => $newName, 'updated_at' => now()]);

                    return;
                }

                if (Schema::hasTable('role_has_permissions')) {
                    DB::table('role_has_permissions')
                        ->where('permission_id', $permission->id)
                        ->get()
                        ->each(fn (object $row) => DB::table('role_has_permissions')->insertOrIgnore([
                            'permission_id' => $existingId,
                            'role_id' => $row->role_id,
                        ]));
                    DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
                }

                if (Schema::hasTable('model_has_permissions')) {
                    DB::table('model_has_permissions')
                        ->where('permission_id', $permission->id)
                        ->get()
                        ->each(fn (object $row) => DB::table('model_has_permissions')->insertOrIgnore([
                            'permission_id' => $existingId,
                            'model_type' => $row->model_type,
                            'model_id' => $row->model_id,
                        ]));
                    DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
                }

                DB::table('permissions')->where('id', $permission->id)->delete();
            });
    }
};
