<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditLogger
{
    public function request(Request $request, Response $response, User $user): void
    {
        if (
            $request->isMethod('OPTIONS') ||
            $request->routeIs('livewire.update') ||
            $request->is('livewire/update')
        ) {
            return;
        }

        $method = strtoupper($request->method());
        $routeName = $request->route()?->getName();

        $this->write([
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'user_email' => $user->email,
            'event' => 'request',
            'description' => $this->requestDescription($method, $request->path()),
            'method' => $method,
            'route_name' => $routeName,
            'path' => '/'.$request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'status_code' => $response->getStatusCode(),
            'metadata' => $this->safeRequestMetadata($request),
        ]);
    }

    public function authentication(User $user, string $event, ?Request $request = null): void
    {
        $request ??= request();

        $this->write([
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'user_email' => $user->email,
            'event' => $event,
            'description' => match ($event) {
                'login' => 'Pengguna masuk ke sistem',
                'login_api' => 'Pengguna masuk melalui API',
                'logout' => 'Pengguna keluar dari sistem',
                default => 'Aktivitas autentikasi pengguna',
            },
            'method' => strtoupper($request->method()),
            'route_name' => $request->route()?->getName(),
            'path' => '/'.$request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'status_code' => null,
            'metadata' => null,
        ]);
    }

    /**
     * Record user-management activity without storing passwords or request payloads.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function userCrud(
        string $action,
        ?User $target = null,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        $actor ??= auth()->user();
        $request = request();

        $targetLabel = $target
            ? trim("{$target->name} ({$target->username})")
            : 'daftar pengguna';

        $description = match ($action) {
            'create' => "Membuat pengguna: {$targetLabel}",
            'read' => "Melihat pengguna: {$targetLabel}",
            'read_list' => 'Melihat daftar pengguna',
            'read_history' => "Melihat riwayat pengguna: {$targetLabel}",
            'update' => "Memperbarui pengguna: {$targetLabel}",
            'roles_update' => "Memperbarui role pengguna: {$targetLabel}",
            'delete' => "Menghapus pengguna: {$targetLabel}",
            default => "Aktivitas pengguna: {$targetLabel}",
        };

        if ($target) {
            $metadata = [
                'target_user' => [
                    'id' => $target->getKey(),
                    'name' => $target->name,
                    'username' => $target->username,
                    'email' => $target->email,
                ],
                ...$metadata,
            ];
        }

        $this->write([
            'user_id' => $actor?->getKey(),
            'user_name' => $actor?->name,
            'user_email' => $actor?->email,
            'event' => 'user_'.$action,
            'description' => Str::limit($description, 255, ''),
            'method' => app()->runningInConsole() ? null : strtoupper($request->method()),
            'route_name' => app()->runningInConsole() ? null : $request->route()?->getName(),
            'path' => app()->runningInConsole() ? null : '/'.$request->path(),
            'ip_address' => app()->runningInConsole() ? null : $request->ip(),
            'user_agent' => app()->runningInConsole()
                ? null
                : Str::limit((string) $request->userAgent(), 1000, ''),
            'status_code' => null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function activity(
        string $event,
        string $description,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        $actor ??= auth()->user();
        $request = request();

        $this->write([
            'user_id' => $actor?->getKey(),
            'user_name' => $actor?->name,
            'user_email' => $actor?->email,
            'event' => Str::limit($event, 50, ''),
            'description' => Str::limit($description, 255, ''),
            'method' => app()->runningInConsole() ? null : strtoupper($request->method()),
            'route_name' => app()->runningInConsole() ? null : $request->route()?->getName(),
            'path' => app()->runningInConsole() ? null : '/'.$request->path(),
            'ip_address' => app()->runningInConsole() ? null : $request->ip(),
            'user_agent' => app()->runningInConsole()
                ? null
                : Str::limit((string) $request->userAgent(), 1000, ''),
            'status_code' => null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Never allow audit logging to interrupt the user's actual request.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            AuditLog::query()->create($attributes);
        } catch (Throwable $exception) {
            logger()->warning('Audit log gagal disimpan.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function requestDescription(string $method, string $path): string
    {
        $action = match ($method) {
            'GET' => 'Membuka halaman',
            'POST' => 'Menjalankan aksi',
            'PUT', 'PATCH' => 'Memperbarui data',
            'DELETE' => 'Menghapus data',
            default => 'Mengakses sistem',
        };

        return Str::limit("{$action}: /{$path}", 255, '…');
    }

    /**
     * Record only structural context. Form values, passwords, and tokens are excluded.
     *
     * @return array<string, mixed>|null
     */
    private function safeRequestMetadata(Request $request): ?array
    {
        $metadata = [];
        $queryKeys = array_keys($request->query());

        if ($queryKeys !== []) {
            $metadata['query_keys'] = $queryKeys;
        }

        return $metadata === [] ? null : $metadata;
    }
}
