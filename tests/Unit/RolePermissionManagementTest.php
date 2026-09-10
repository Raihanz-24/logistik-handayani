<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RolePermissionManagementTest extends TestCase
{
    public function test_custom_role_page_exposes_the_permissions_for_the_new_modules(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = (string) file_get_contents($root.'/app/Support/RolePermissionCatalog.php');
        $resource = (string) file_get_contents($root.'/app/Filament/Resources/RoleResource.php');
        $routes = (string) file_get_contents($root.'/routes/web.php');

        $this->assertStringContainsString("'view_foto_barang_maps'", $catalog);
        $this->assertStringContainsString("'manage_foto_barang_maps'", $catalog);
        $this->assertStringContainsString("'access_foto_barang_editor'", $catalog);
        $this->assertStringContainsString("'view_any_kalkulator_belanja'", $catalog);
        $this->assertStringContainsString("'create_kalkulator_belanja'", $catalog);
        $this->assertStringContainsString("'update_kalkulator_belanja'", $catalog);
        $this->assertStringContainsString('public static function groups', $catalog);
        $this->assertStringContainsString("'foto_maps'", $catalog);
        $this->assertStringContainsString("protected static ?string \$slug = 'peran';", $resource);
        $this->assertStringContainsString('permissionSections', $resource);
        $this->assertStringContainsString('->collapsible()', $resource);
        $this->assertStringContainsString('permission_groups.', $resource);
        $this->assertStringContainsString("Pages\\CreateRole::route('/buat')", $resource);
        $this->assertStringContainsString("Pages\\EditRole::route('/{record}/ubah')", $resource);
        $this->assertStringContainsString("Route::redirect('/admin/shield/roles/{legacyPath?}', '/admin/peran')", $routes);
    }

    public function test_photo_maps_view_permission_is_read_only_and_editor_is_separate(): void
    {
        $root = dirname(__DIR__, 2);
        $maps = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangMaps.php');
        $folder = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangFolder.php');
        $editor = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangEditor.php');
        $mediaController = (string) file_get_contents($root.'/app/Http/Controllers/FotoBarangMediaController.php');

        $this->assertStringContainsString('public function canManagePhotos', $maps);
        $this->assertStringContainsString('ensureCanManagePhotos', $maps);
        $this->assertStringContainsString('public function canManagePhotos', $folder);
        $this->assertStringContainsString("can('access_foto_barang_editor')", $editor);
        $this->assertStringContainsString("can('view_foto_barang_maps')", $mediaController);
    }
}
