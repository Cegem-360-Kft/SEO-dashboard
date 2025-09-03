<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TenantAuthController extends Controller
{
    /**
     * Handle tenant-aware login
     */
    public function login(Request $request)
    {
        $this->ensureIsNotRateLimited($request);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_identifier' => 'nullable|string', // slug or domain
        ]);

        $email = $request->email;
        $password = $request->password;
        $tenantIdentifier = $request->tenant_identifier;

        // Find user by email first
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            RateLimiter::hit($this->throttleKey($request));
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // If tenant identifier is provided, validate it matches user's tenant
        if ($tenantIdentifier) {
            $tenant = Tenant::where('slug', $tenantIdentifier)
                           ->orWhere('domain', $tenantIdentifier)
                           ->first();

            if (!$tenant || $user->tenant_id !== $tenant->id) {
                RateLimiter::hit($this->throttleKey($request));
                throw ValidationException::withMessages([
                    'tenant_identifier' => ['Invalid tenant for this user.'],
                ]);
            }
        }

        // Validate tenant and user status
        if (!$user->tenant || !$user->tenant->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your tenant account is inactive.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your user account is deactivated.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        Auth::login($user, $request->boolean('remember'));
        
        // Record login and audit log
        $user->recordLogin();
        AuditLog::logAuthEvent('user.login', $user, [
            'tenant_slug' => $tenantIdentifier,
            'remember' => $request->boolean('remember'),
        ]);

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load('tenant'),
            'redirect_url' => route('dashboard')
        ]);
    }

    /**
     * Handle user logout
     */
    public function logout(Request $request)
    {
        $user = Auth::user();
        
        // Log logout event before actual logout
        if ($user) {
            AuditLog::logAuthEvent('user.logout', $user);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout successful']);
    }

    /**
     * Generate API token for authenticated user
     */
    public function createApiToken(Request $request)
    {
        $request->validate([
            'token_name' => 'required|string|max:255',
            'abilities' => 'sometimes|array',
        ]);

        $user = $request->user();

        // Create token with specific abilities or default ones
        $abilities = $request->abilities ?? ['*']; // Default to all abilities

        $token = $user->createToken(
            $request->token_name,
            $abilities,
            now()->addYear() // Token expires in 1 year
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
        ]);
    }

    /**
     * Revoke API token
     */
    public function revokeApiToken(Request $request)
    {
        $request->validate([
            'token_id' => 'required|integer',
        ]);

        $user = $request->user();
        $token = $user->tokens()->where('id', $request->token_id)->first();

        if (!$token) {
            return response()->json(['message' => 'Token not found'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked successfully']);
    }

    /**
     * Get all user's API tokens
     */
    public function getApiTokens(Request $request)
    {
        $user = $request->user();
        
        $tokens = $user->tokens()->select('id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at')->get();

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    protected function throttleKey(Request $request): string
    {
        return strtolower($request->input('email')).'|'.$request->ip();
    }

    /**
     * Ensure the login request is not rate limited.
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }
}