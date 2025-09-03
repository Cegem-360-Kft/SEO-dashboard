<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TenantRegistrationController extends Controller
{
    /**
     * Register a new tenant with owner user
     */
    public function registerTenant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_name' => 'required|string|max:255|unique:tenants,name',
            'tenant_slug' => 'required|string|max:255|unique:tenants,slug|alpha_dash',
            'tenant_domain' => 'nullable|string|max:255|unique:tenants,domain',
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|string|email|max:255',
            'owner_password' => 'required|string|min:8|confirmed',
            'plan' => 'sometimes|in:basic,professional,enterprise',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if user with this email already exists in any tenant
        $existingUser = User::where('email', $request->owner_email)->first();
        if ($existingUser) {
            return response()->json([
                'errors' => ['owner_email' => ['This email is already registered.']]
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Create tenant
            $tenant = Tenant::create([
                'name' => $request->tenant_name,
                'slug' => $request->tenant_slug,
                'domain' => $request->tenant_domain,
                'plan' => $request->plan ?? 'basic',
                'is_active' => true,
                'trial_ends_at' => now()->addDays(14), // 14-day trial
            ]);

            // Create owner user
            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->owner_name,
                'email' => $request->owner_email,
                'password' => Hash::make($request->owner_password),
                'role' => 'owner',
                'is_active' => true,
                'timezone' => 'UTC',
                'language' => 'en',
            ]);

            // Set up default permissions and roles for this tenant
            $this->setupTenantPermissions($tenant, $owner);

            DB::commit();

            // Log in the newly created owner
            Auth::login($owner);

            return response()->json([
                'message' => 'Tenant and owner account created successfully',
                'tenant' => $tenant,
                'user' => $owner,
                'redirect_url' => route('dashboard')
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add user to existing tenant (invitation-based)
     */
    public function addUserToTenant(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:admin,manager,viewer',
        ]);

        $tenant = Tenant::findOrFail($request->tenant_id);

        // Check if tenant can add more users
        if (!$tenant->canAddUsers()) {
            return response()->json([
                'message' => 'Tenant has reached maximum user limit for current plan'
            ], 403);
        }

        // Check if user with this email already exists in this tenant
        $existingUser = User::where('email', $request->email)
                          ->where('tenant_id', $tenant->id)
                          ->first();

        if ($existingUser) {
            return response()->json([
                'errors' => ['email' => ['User already exists in this tenant.']]
            ], 422);
        }

        // Create user
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'is_active' => true,
            'timezone' => 'UTC',
            'language' => 'en',
        ]);

        // Assign role-based permissions
        $this->assignUserRole($user, $request->role, $tenant);

        return response()->json([
            'message' => 'User added to tenant successfully',
            'user' => $user->load('tenant')
        ], 201);
    }

    /**
     * Setup default permissions and roles for new tenant
     */
    protected function setupTenantPermissions(Tenant $tenant, User $owner)
    {
        // Define SEO platform permissions
        $permissions = [
            // Project management
            'projects.view',
            'projects.create',
            'projects.edit',
            'projects.delete',
            
            // Keyword management
            'keywords.view',
            'keywords.create',
            'keywords.edit',
            'keywords.delete',
            'keywords.track',
            
            // Reporting
            'reports.view',
            'reports.create',
            'reports.export',
            'reports.schedule',
            
            // User management
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.invite',
            
            // Settings
            'settings.view',
            'settings.edit',
            'settings.integrations',
            
            // API access
            'api.access',
            'api.tokens.create',
            'api.tokens.revoke',
        ];

        // Create tenant-scoped permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }

        // Create tenant-scoped roles
        $ownerRole = Role::create([
            'name' => 'owner',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $adminRole = Role::create([
            'name' => 'admin', 
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'guard_name' => 'web', 
            'tenant_id' => $tenant->id,
        ]);

        $viewerRole = Role::create([
            'name' => 'viewer',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        // Assign all permissions to owner
        $ownerRole->syncPermissions(Permission::where('tenant_id', $tenant->id)->get());

        // Assign permissions to other roles
        $adminPermissions = Permission::where('tenant_id', $tenant->id)
            ->whereNotIn('name', ['users.delete', 'settings.integrations'])
            ->get();
        $adminRole->syncPermissions($adminPermissions);

        $managerPermissions = Permission::where('tenant_id', $tenant->id)
            ->whereNotIn('name', ['users.create', 'users.edit', 'users.delete', 'users.invite', 'settings.edit', 'settings.integrations'])
            ->get();
        $managerRole->syncPermissions($managerPermissions);

        $viewerPermissions = Permission::where('tenant_id', $tenant->id)
            ->whereIn('name', ['projects.view', 'keywords.view', 'reports.view'])
            ->get();
        $viewerRole->syncPermissions($viewerPermissions);

        // Assign owner role to the owner user
        $owner->assignRole($ownerRole);
    }

    /**
     * Assign appropriate role to user
     */
    protected function assignUserRole(User $user, string $roleName, Tenant $tenant)
    {
        $role = Role::where('name', $roleName)
                   ->where('tenant_id', $tenant->id)
                   ->first();

        if ($role) {
            $user->assignRole($role);
        }
    }
}