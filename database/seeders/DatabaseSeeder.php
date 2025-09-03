<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Tenant;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create a default tenant first
        $tenant = Tenant::factory()->withPlan('professional')->create([
            'name' => 'Default Company',
            'slug' => 'default-company',
            'domain' => 'example.com',
        ]);

        // Create an admin user for the tenant
        User::factory()->owner()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'admin@admin.com',
        ]);

        // Optionally call the TenantPermissionSeeder
        $this->call(TenantPermissionSeeder::class);
    }
}
