<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ApiTenantMiddleware
{
    /**
     * Handle an incoming request for API routes with tenant context
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // For API routes, user might be authenticated via Sanctum token
        if (! Auth::guard('sanctum')->check()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = Auth::guard('sanctum')->user();

        // Validate tenant context
        if (! $user->tenant_id || ! $user->tenant || ! $user->tenant->is_active) {
            return response()->json([
                'message' => 'Invalid or inactive tenant context',
            ], 403);
        }

        // Validate user is active
        if (! $user->is_active) {
            return response()->json([
                'message' => 'User account is deactivated',
            ], 403);
        }

        // Set current tenant for API context
        app()->instance('current_tenant', $user->tenant);

        return $next($request);
    }
}
