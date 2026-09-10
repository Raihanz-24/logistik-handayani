<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionCatalog
{
    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [
            'view_any_barang' => 'Master Barang — lihat daftar',
            'view_barang' => 'Master Barang — lihat detail',
            'create_barang' => 'Master Barang — tambah',
            'update_barang' => 'Master Barang — ubah',
            'delete_barang' => 'Master Barang — hapus',
            'view_any_barang::lokasi' => 'Stok Barang — lihat daftar',
            'view_barang::lokasi' => 'Stok Barang — lihat detail',
            'view_any_kategori::barang' => 'Kategori Barang — lihat daftar',
            'view_kategori::barang' => 'Kategori Barang — lihat detail',
            'create_kategori::barang' => 'Kategori Barang — tambah',
            'update_kategori::barang' => 'Kategori Barang — ubah',
            'delete_kategori::barang' => 'Kategori Barang — hapus',
            'view_any_lokasi' => 'Lokasi Gudang — lihat daftar',
            'view_lokasi' => 'Lokasi Gudang — lihat detail',
            'create_lokasi' => 'Lokasi Gudang — tambah',
            'update_lokasi' => 'Lokasi Gudang — ubah',
            'delete_lokasi' => 'Lokasi Gudang — hapus',
            'view_any_supplier' => 'Supplier — lihat daftar',
            'view_supplier' => 'Supplier — lihat detail',
            'create_supplier' => 'Supplier — tambah',
            'update_supplier' => 'Supplier — ubah',
            'delete_supplier' => 'Supplier — hapus',
            'view_any_mutasi' => 'Mutasi — lihat daftar',
            'view_mutasi' => 'Mutasi — lihat detail',
            'create_mutasi' => 'Mutasi — buat',
            'update_mutasi' => 'Mutasi — ubah',
            'delete_mutasi' => 'Mutasi — hapus',
            'view_any_kalkulator_belanja' => 'Transaksi Belanja — lihat daftar',
            'view_kalkulator_belanja' => 'Transaksi Belanja — lihat detail',
            'create_kalkulator_belanja' => 'Transaksi Belanja — buat sesi',
            'update_kalkulator_belanja' => 'Transaksi Belanja — ubah sesi dan transaksi toko',
            'delete_kalkulator_belanja' => 'Transaksi Belanja — hapus',
            'view_any_catatan' => 'Catatan — lihat daftar',
            'view_catatan' => 'Catatan — lihat detail',
            'create_catatan' => 'Catatan — tambah',
            'update_catatan' => 'Catatan — ubah',
            'delete_catatan' => 'Catatan — hapus',
            'view_foto_barang_maps' => 'Foto Maps — lihat folder dan foto hasil',
            'manage_foto_barang_maps' => 'Foto Maps — buat sesi, potret, label, dan hapus',
            'access_foto_barang_editor' => 'Editor Foto Maps — buka dan membuat hasil edit',
        ];

        $options += [
            'delete_any_barang' => 'Master Barang - hapus banyak',
            'create_barang::lokasi' => 'Stok Barang - tambah',
            'update_barang::lokasi' => 'Stok Barang - ubah',
            'delete_barang::lokasi' => 'Stok Barang - hapus',
            'delete_any_barang::lokasi' => 'Stok Barang - hapus banyak',
            'delete_any_kategori::barang' => 'Kategori Barang - hapus banyak',
            'delete_any_lokasi' => 'Lokasi Gudang - hapus banyak',
            'view_any_user' => 'Pengguna - lihat daftar',
            'view_user' => 'Pengguna - lihat detail',
            'create_user' => 'Pengguna - tambah',
            'update_user' => 'Pengguna - ubah',
            'delete_user' => 'Pengguna - hapus',
            'delete_any_user' => 'Pengguna - hapus banyak',
            'delete_any_mutasi' => 'Mutasi - hapus banyak',
        ];

        if (! Schema::hasTable('permissions')) {
            return $options;
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->each(fn (string $name) => $options[$name] ??= 'Izin tambahan — '.$name);

        return $options;
    }

    /**
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public static function groups(): array
    {
        $options = static::options();
        $definitions = [
            'barang' => [
                'label' => 'Master Barang',
                'names' => [
                    'view_any_barang', 'view_barang', 'create_barang', 'update_barang',
                    'delete_barang', 'delete_any_barang',
                ],
            ],
            'stok_dan_gudang' => [
                'label' => 'Stok & Gudang',
                'names' => [
                    'view_any_barang::lokasi', 'view_barang::lokasi', 'create_barang::lokasi',
                    'update_barang::lokasi', 'delete_barang::lokasi', 'delete_any_barang::lokasi',
                    'view_any_kategori::barang', 'view_kategori::barang', 'create_kategori::barang',
                    'update_kategori::barang', 'delete_kategori::barang', 'delete_any_kategori::barang',
                    'view_any_lokasi', 'view_lokasi', 'create_lokasi', 'update_lokasi',
                    'delete_lokasi', 'delete_any_lokasi',
                ],
            ],
            'mutasi' => [
                'label' => 'Mutasi Barang',
                'names' => [
                    'view_any_mutasi', 'view_mutasi', 'create_mutasi', 'update_mutasi',
                    'delete_mutasi', 'delete_any_mutasi',
                ],
            ],
            'transaksi_belanja' => [
                'label' => 'Transaksi Belanja & Supplier',
                'names' => [
                    'view_any_kalkulator_belanja', 'view_kalkulator_belanja',
                    'create_kalkulator_belanja', 'update_kalkulator_belanja', 'delete_kalkulator_belanja',
                    'view_any_supplier', 'view_supplier', 'create_supplier', 'update_supplier', 'delete_supplier',
                ],
            ],
            'catatan' => [
                'label' => 'Catatan',
                'names' => [
                    'view_any_catatan', 'view_catatan', 'create_catatan', 'update_catatan', 'delete_catatan',
                ],
            ],
            'foto_maps' => [
                'label' => 'Foto Maps',
                'names' => [
                    'view_foto_barang_maps', 'manage_foto_barang_maps', 'access_foto_barang_editor',
                ],
            ],
            'pengguna' => [
                'label' => 'Pengguna',
                'names' => [
                    'view_any_user', 'view_user', 'create_user', 'update_user', 'delete_user', 'delete_any_user',
                ],
            ],
        ];

        $groups = [];
        $assigned = [];

        foreach ($definitions as $key => $definition) {
            $groupOptions = array_intersect_key($options, array_flip($definition['names']));
            $groups[$key] = [
                'label' => $definition['label'],
                'options' => $groupOptions,
            ];
            $assigned = [...$assigned, ...array_keys($groupOptions)];
        }

        $remaining = array_diff_key($options, array_flip($assigned));

        if ($remaining !== []) {
            $groups['lainnya'] = [
                'label' => 'Izin Lainnya',
                'options' => $remaining,
            ];
        }

        return $groups;
    }

    /** @param array<int, mixed> $permissions
     * @return array<string, array<int, string>>
     */
    public static function groupedValues(array $permissions): array
    {
        $selected = collect($permissions)->filter('is_string')->all();

        return collect(static::groups())
            ->mapWithKeys(fn (array $group, string $key): array => [
                $key => array_values(array_intersect(array_keys($group['options']), $selected)),
            ])
            ->all();
    }

    /** @param array<string, mixed> $permissionGroups
     * @return array<int, string>
     */
    public static function flattenGroups(array $permissionGroups): array
    {
        return collect($permissionGroups)
            ->flatMap(fn (mixed $permissions): array => is_array($permissions) ? $permissions : [])
            ->filter('is_string')
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $permissionNames */
    public static function sync(Role $role, array $permissionNames): void
    {
        $allowed = array_keys(static::options());
        $selected = collect($permissionNames)
            ->filter(fn (mixed $name): bool => is_string($name) && in_array($name, $allowed, true))
            ->unique()
            ->values();

        $permissions = $selected->map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
        );

        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
