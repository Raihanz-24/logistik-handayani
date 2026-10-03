<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Sso\UserLinkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UserLinkResolver — resolusi portal_uuid → user (TANPA auto-create).
 */
class SsoUserLinkResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): UserLinkResolver
    {
        return app(UserLinkResolver::class);
    }

    public function test_resolve_returns_user_for_known_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $user = User::factory()->create(['portal_uuid' => $uuid]);

        $found = $this->resolver()->resolve($uuid);

        $this->assertNotNull($found);
        $this->assertSame($user->getKey(), $found->getKey());
    }

    public function test_resolve_returns_null_for_unknown_or_blank_uuid(): void
    {
        $this->assertNull($this->resolver()->resolve((string) Str::uuid()));
        $this->assertNull($this->resolver()->resolve(''));
        $this->assertNull($this->resolver()->resolve('   '));
    }

    public function test_ensure_uuid_generates_once_and_is_idempotent(): void
    {
        $user = User::factory()->create(['portal_uuid' => null]);

        $first = $this->resolver()->ensureUuid($user);
        $second = $this->resolver()->ensureUuid($user->fresh());

        $this->assertTrue(Str::isUuid($first));
        $this->assertSame($first, $second);
        $this->assertSame($first, $user->fresh()->portal_uuid);
    }

    public function test_ensure_uuid_keeps_existing_value(): void
    {
        $uuid = (string) Str::uuid();
        $user = User::factory()->create(['portal_uuid' => $uuid]);

        $this->assertSame($uuid, $this->resolver()->ensureUuid($user));
    }
}
