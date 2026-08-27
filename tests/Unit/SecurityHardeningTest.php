<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_public_registration_is_disabled(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $apiRoutes = file_get_contents($projectRoot.'/routes/api.php');
        $panelProvider = file_get_contents($projectRoot.'/app/Providers/Filament/AdminPanelProvider.php');

        $this->assertStringNotContainsString("Route::post('/register'", $apiRoutes);
        $this->assertStringNotContainsString('->registration()', $panelProvider);
    }

    public function test_api_uses_scoped_tokens_and_authorization(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $apiRoutes = file_get_contents($projectRoot.'/routes/api.php');
        $userController = file_get_contents($projectRoot.'/app/Http/Controllers/Api/UserController.php');

        $this->assertStringContainsString("'abilities:api:access'", $apiRoutes);
        $this->assertStringContainsString("Gate::authorize('viewAny', User::class)", $userController);
        $this->assertStringContainsString("Gate::authorize('delete', \$user)", $userController);
    }

    public function test_user_crud_has_domain_audit_events(): void
    {
        $auditLogger = file_get_contents(dirname(__DIR__, 2).'/app/Services/AuditLogger.php');

        foreach (['create', 'read', 'update', 'roles_update', 'delete'] as $action) {
            $this->assertStringContainsString("'{$action}' =>", $auditLogger);
        }

        $this->assertStringNotContainsString("'password' => \$target", $auditLogger);
    }
}
