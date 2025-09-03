<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a super admin tenant for system administration
        $superTenant = Tenant::firstOrCreate(
            ['slug' => 'super-admin'],
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Super Admin',
                'plan' => 'enterprise',
                'max_projects' => 999999,
                'max_keywords' => 999999,
                'max_users' => 999999,
                'is_active' => true,
            ]
        );

        // Create super admin user
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@seodashboard.local'],
            [
                'tenant_id' => $superTenant->id,
                'name' => 'Super Administrator',
                'password' => Hash::make('SuperSecure123!'),
                'role' => 'owner',
                'is_active' => true,
                'timezone' => 'UTC',
                'language' => 'en',
            ]
        );

        // Create demo tenant for testing
        $demoTenant = Tenant::firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => 'Demo Company',
                'plan' => 'professional',
                'max_projects' => 10,
                'max_keywords' => 500,
                'max_users' => 5,
                'is_active' => true,
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        // Create demo users
        $demoOwner = User::firstOrCreate(
            ['email' => 'owner@demo.local'],
            [
                'tenant_id' => $demoTenant->id,
                'name' => 'Demo Owner',
                'password' => Hash::make('DemoOwner123!'),
                'role' => 'owner',
                'is_active' => true,
                'timezone' => 'UTC',
                'language' => 'en',
            ]
        );

        $demoManager = User::firstOrCreate(
            ['email' => 'manager@demo.local'],
            [
                'tenant_id' => $demoTenant->id,
                'name' => 'Demo Manager',
                'password' => Hash::make('DemoManager123!'),
                'role' => 'manager',
                'is_active' => true,
                'timezone' => 'UTC',
                'language' => 'en',
            ]
        );

        $demoViewer = User::firstOrCreate(
            ['email' => 'viewer@demo.local'],
            [
                'tenant_id' => $demoTenant->id,
                'name' => 'Demo Viewer',
                'password' => Hash::make('DemoViewer123!'),
                'role' => 'viewer',
                'is_active' => true,
                'timezone' => 'UTC',
                'language' => 'en',
            ]
        );

        // Set up permissions for both tenants
        $this->setupTenantPermissions($superTenant, $superAdmin);
        $this->setupTenantPermissions($demoTenant, $demoOwner);

        // Assign roles to demo users
        $this->assignUserRoles($demoTenant, [
            ['user' => $demoManager, 'role' => 'manager'],
            ['user' => $demoViewer, 'role' => 'viewer'],
        ]);

        $this->command->info('Tenant permissions and demo users created successfully!');
        $this->command->info('Super Admin: admin@seodashboard.local / SuperSecure123!');
        $this->command->info('Demo Owner: owner@demo.local / DemoOwner123!');
        $this->command->info('Demo Manager: manager@demo.local / DemoManager123!');
        $this->command->info('Demo Viewer: viewer@demo.local / DemoViewer123!');
    }

    /**
     * Setup permissions and roles for a tenant
     */
    protected function setupTenantPermissions(Tenant $tenant, User $owner)
    {
        // Define comprehensive permissions for SEO platform
        $permissions = [
            // Project management
            'projects.view' => 'View projects',
            'projects.create' => 'Create projects',
            'projects.edit' => 'Edit projects',
            'projects.delete' => 'Delete projects',
            
            // Keyword management
            'keywords.view' => 'View keywords',
            'keywords.create' => 'Create keywords',
            'keywords.edit' => 'Edit keywords',
            'keywords.delete' => 'Delete keywords',
            'keywords.track' => 'Track keyword positions',
            'keywords.import' => 'Import keywords',
            'keywords.export' => 'Export keywords',
            
            // SERP Features
            'serp.view' => 'View SERP features',
            'serp.analyze' => 'Analyze SERP features',
            
            // Competitor analysis
            'competitors.view' => 'View competitors',
            'competitors.add' => 'Add competitors',
            'competitors.edit' => 'Edit competitors',
            'competitors.delete' => 'Delete competitors',
            'competitors.analyze' => 'Analyze competitors',
            
            // Reporting
            'reports.view' => 'View reports',
            'reports.create' => 'Create reports',
            'reports.edit' => 'Edit reports',
            'reports.delete' => 'Delete reports',
            'reports.export' => 'Export reports',
            'reports.schedule' => 'Schedule reports',
            
            // User management
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit users',
            'users.delete' => 'Delete users',
            'users.invite' => 'Invite users',
            'users.deactivate' => 'Deactivate users',
            
            // Settings
            'settings.view' => 'View settings',
            'settings.edit' => 'Edit settings',
            'settings.integrations' => 'Manage integrations',
            'settings.api' => 'Manage API settings',
            'settings.notifications' => 'Manage notifications',
            
            // API access
            'api.access' => 'Access API',
            'api.tokens.create' => 'Create API tokens',
            'api.tokens.revoke' => 'Revoke API tokens',
            'api.webhook.manage' => 'Manage webhooks',
            
            // Advanced features
            'analytics.view' => 'View analytics',
            'analytics.advanced' => 'Access advanced analytics',
            'data.export' => 'Export data',
            'data.import' => 'Import data',
            'audit.view' => 'View audit logs',
        ];

        // Create tenant-scoped permissions
        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }

        // Create tenant-scoped roles
        $roles = [
            'owner' => [
                'description' => 'Full access to all features',
                'permissions' => array_keys($permissions),
            ],
            'admin' => [
                'description' => 'Admin access with some restrictions',
                'permissions' => array_filter(array_keys($permissions), function($perm) {
                    return !in_array($perm, ['users.delete', 'settings.integrations']);
                }),
            ],
            'manager' => [
                'description' => 'Project and keyword management access',
                'permissions' => [
                    'projects.view', 'projects.create', 'projects.edit',
                    'keywords.view', 'keywords.create', 'keywords.edit', 'keywords.track', 'keywords.import', 'keywords.export',
                    'serp.view', 'serp.analyze',
                    'competitors.view', 'competitors.add', 'competitors.edit', 'competitors.analyze',
                    'reports.view', 'reports.create', 'reports.edit', 'reports.export',
                    'settings.view', 'settings.notifications',
                    'analytics.view',
                    'data.export',
                ],
            ],
            'viewer' => [
                'description' => 'Read-only access to reports and data',
                'permissions' => [
                    'projects.view',
                    'keywords.view',
                    'serp.view',
                    'competitors.view',
                    'reports.view', 'reports.export',
                    'analytics.view',
                ],
            ],
        ];

        foreach ($roles as $roleName => $roleData) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);

            // Assign permissions to role
            $rolePermissions = Permission::where('tenant_id', $tenant->id)
                ->whereIn('name', $roleData['permissions'])
                ->get();
            
            $role->syncPermissions($rolePermissions);
        }

        // Assign owner role to the owner user with team context
        $ownerRole = Role::where('name', 'owner')
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if ($ownerRole) {
            // Set team context before assigning role
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $owner->assignRole($ownerRole);
        }
    }

    /**
     * Assign roles to users with team context
     */
    protected function assignUserRoles(Tenant $tenant, array $assignments)
    {
        foreach ($assignments as $assignment) {
            $role = Role::where('name', $assignment['role'])
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($role) {
                // Set team context before assigning role
                app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
                $assignment['user']->assignRole($role);
            }
        }
    }
}