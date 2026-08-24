<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditUserActivity
{
    public function __construct(private readonly AuditLogger $logger) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $user ??= $request->user();

            if ($user instanceof User) {
                $this->logger->request($request, response('', 500), $user);
            }

            throw $exception;
        }

        $user ??= $request->user();

        if ($user instanceof User) {
            $this->logger->request($request, $response, $user);
        }

        return $response;
    }
}
