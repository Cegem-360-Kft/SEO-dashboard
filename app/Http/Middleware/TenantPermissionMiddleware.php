<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

final class TenantPermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null): Response
    {
        $authGuard = Auth::guard($guard);

        if ($authGuard->guest()) {
            throw UnauthorizedException::notLoggedIn();
        }

        $user = $authGuard->user();

        // Check if user belongs to a tenant and has the permission within that tenant context
        if (! $user->tenant_id) {
            throw UnauthorizedException::forPermissions([$permission]);
        }

        // Use tenant-scoped permission checking
        if (! $user->hasPermissionTo($permission, $guard)) {
            throw UnauthorizedException::forPermissions([$permission]);
        }

        return $next($request);
    }
}
