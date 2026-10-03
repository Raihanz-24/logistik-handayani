<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Commands sso:show-uuid / sso:assign-uuid.
 */
class SsoUuidCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_uuid_creates_uuid_and_is_idempotent(): void
    {
        $user = User::factory()->create(['username' => 'reyhan', 'portal_uuid' => null]);

        $this->artisan('sso:assign-uuid', ['email' => 'reyhan'])
            ->assertSuccessful();

        $uuid = $user->fresh()->portal_uuid;
        $this->assertTrue(Str::isUuid($uuid));

        // Idempotent: nilai tidak berubah.
        $this->artisan('sso:assign-uuid', ['email' => 'reyhan'])->assertSuccessful();
        $this->assertSame($uuid, $user->fresh()->portal_uuid);
    }

    public function test_show_uuid_outputs_existing_uuid(): void
    {
        $uuid = (string) Str::uuid();
        User::factory()->create(['username' => 'reyhan', 'portal_uuid' => $uuid]);

        $this->artisan('sso:show-uuid', ['email' => 'reyhan'])
            ->expectsOutputToContain($uuid)
            ->assertSuccessful();
    }

    public function test_show_uuid_warns_when_missing(): void
    {
        User::factory()->create(['username' => 'reyhan', 'portal_uuid' => null]);

        $this->artisan('sso:show-uuid', ['email' => 'reyhan'])
            ->assertSuccessful();
    }

    public function test_commands_fail_for_unknown_user(): void
    {
        $this->artisan('sso:assign-uuid', ['email' => 'nobody'])->assertFailed();
        $this->artisan('sso:show-uuid', ['email' => 'nobody'])->assertFailed();
    }
}
