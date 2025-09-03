<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        // Ensure user has a tenant_id and tenant is active
        if (!$user->tenant_id || !$user->tenant || !$user->tenant->is_active) {
            Auth::logout();
            return redirect()->route('login')->withErrors([
                'email' => 'Your account is associated with an inactive tenant.'
            ]);
        }

        // Ensure user is active
        if (!$user->is_active) {
            Auth::logout();
            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated.'
            ]);
        }

        // Set tenant context for the request
        app()->instance('current_tenant', $user->tenant);

        return $next($request);
    }
}