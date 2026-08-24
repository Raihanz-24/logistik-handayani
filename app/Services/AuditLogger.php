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
