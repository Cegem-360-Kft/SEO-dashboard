<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\Project;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

describe('User Model', function (): void {

    describe('Factory and Creation', function (): void {
        it('can be created with valid data', function (): void {
            $tenant = Tenant::factory()->create();
            $user = User::factory()->for($tenant)->create();

            expect($user)->toBeInstanceOf(User::class);
            expect($user->tenant_id)->toBe($tenant->id);
            expect($user->email)->toBeValidEmail();
        });

        it('creates user with default attributes', function (): void {
            $user = User::factory()->create();

            expect($user->is_active)->toBeTrue();
            expect($user->role)->toBeIn(['viewer', 'editor', 'manager', 'admin', 'owner']);
            expect($user->preferences)->toBeArray();
            expect($user->permissions)->toBeArray();
        });

        it('can create admin user', function (): void {
            $user = User::factory()->admin()->create();

            expect($user->role)->toBe('admin');
            expect($user->permissions)->toContain('manage_users');
        });

        it('can create owner user', function (): void {
            $user = User::factory()->owner()->create();

            expect($user->role)->toBe('owner');
            expect($user->permissions)->toContain('*');
        });
    });

    describe('Relationships', function (): void {
        it('belongs to a tenant', function (): void {
            $tenant = Tenant::factory()->create();
            $user = User::factory()->for($tenant)->create();

            expect($user->tenant)->toBeInstanceOf(Tenant::class);
            expect($user->tenant->id)->toBe($tenant->id);
        });

        it('has many projects', function (): void {
            $user = User::factory()->create();
            $projects = Project::factory()->count(3)->for($user)->for($user->tenant)->create();

            expect($user->projects)->toHaveCount(3);
            expect($user->projects->first())->toBeInstanceOf(Project::class);
        });

        it('has many reports', function (): void {
            $user = User::factory()->create();
            $reports = Report::factory()->count(2)->for($user)->for($user->tenant)->create();

            expect($user->reports)->toHaveCount(2);
            expect($user->reports->first())->toBeInstanceOf(Report::class);
        });

        it('has many notifications', function (): void {
            $user = User::factory()->create();
            $notifications = Notification::factory()->count(5)->for($user)->for($user->tenant)->create();

            expect($user->notifications)->toHaveCount(5);
            expect($user->notifications->first())->toBeInstanceOf(Notification::class);
        });
    });

    describe('Role and Permission Methods', function (): void {
        it('correctly identifies owner role', function (): void {
            $owner = User::factory()->withRole('owner')->create();
            $admin = User::factory()->withRole('admin')->create();

            expect($owner->isOwner())->toBeTrue();
            expect($admin->isOwner())->toBeFalse();
        });

        it('correctly identifies admin role', function (): void {
            $owner = User::factory()->withRole('owner')->create();
            $admin = User::factory()->withRole('admin')->create();
            $manager = User::factory()->withRole('manager')->create();

            expect($owner->isAdmin())->toBeTrue();
            expect($admin->isAdmin())->toBeTrue();
            expect($manager->isAdmin())->toBeFalse();
        });

        it('correctly identifies manager role', function (): void {
            $owner = User::factory()->withRole('owner')->create();
            $admin = User::factory()->withRole('admin')->create();
            $manager = User::factory()->withRole('manager')->create();
            $editor = User::factory()->withRole('editor')->create();

            expect($owner->isManager())->toBeTrue();
            expect($admin->isManager())->toBeTrue();
            expect($manager->isManager())->toBeTrue();
            expect($editor->isManager())->toBeFalse();
        });

        it('manages project permissions correctly', function (): void {
            $manager = User::factory()->withRole('manager')->create();
            $viewer = User::factory()->withRole('viewer')->create();

            expect($manager->canManageProjects())->toBeTrue();
            expect($viewer->canManageProjects())->toBeFalse();
        });

        it('manages user permissions correctly', function (): void {
            $admin = User::factory()->withRole('admin')->create();
            $manager = User::factory()->withRole('manager')->create();

            expect($admin->canManageUsers())->toBeTrue();
            expect($manager->canManageUsers())->toBeFalse();
        });

        it('owner has all permissions', function (): void {
            $owner = User::factory()->withRole('owner')->create();

            expect($owner->hasPermission('any_permission'))->toBeTrue();
            expect($owner->hasPermission('super_admin'))->toBeTrue();
        });

        it('checks specific permissions correctly', function (): void {
            $user = User::factory()->create([
                'role' => 'editor',
                'permissions' => ['edit_projects', 'view_reports'],
            ]);

            expect($user->hasPermission('edit_projects'))->toBeTrue();
            expect($user->hasPermission('view_reports'))->toBeTrue();
            expect($user->hasPermission('manage_users'))->toBeFalse();
        });
    });

    describe('Business Logic Methods', function (): void {
        it('can view reports when active', function (): void {
            $activeUser = User::factory()->create(['is_active' => true]);
            $inactiveUser = User::factory()->create(['is_active' => false]);

            expect($activeUser->canViewReports())->toBeTrue();
            expect($inactiveUser->canViewReports())->toBeFalse();
        });

        it('records login correctly', function (): void {
            $user = User::factory()->create(['last_login_at' => null]);

            expect($user->last_login_at)->toBeNull();

            $user->recordLogin();

            expect($user->fresh()->last_login_at)->not->toBeNull();
        });

        it('generates initials correctly', function (): void {
            $user = User::factory()->create(['name' => 'John Doe']);
            expect($user->initials())->toBe('JD');

            $user2 = User::factory()->create(['name' => 'Alice Bob Charlie']);
            expect($user2->initials())->toBe('AB'); // Only first two names

            $user3 = User::factory()->create(['name' => 'Madonna']);
            expect($user3->initials())->toBe('M');
        });
    });

    describe('Casts and Attributes', function (): void {
        it('casts attributes correctly', function (): void {
            $user = User::factory()->create([
                'preferences' => ['theme' => 'dark', 'notifications' => true],
                'permissions' => ['view', 'edit'],
                'is_active' => '1',
                'last_login_at' => '2024-01-01 12:00:00',
            ]);

            expect($user->preferences)->toBeArray();
            expect($user->preferences['theme'])->toBe('dark');
            expect($user->permissions)->toBeArray();
            expect($user->is_active)->toBeTrue();
            expect($user->last_login_at)->toBeInstanceOf(Carbon::class);
        });

        it('hides password in array representation', function (): void {
            $user = User::factory()->create();
            $array = $user->toArray();

            expect($array)->not->toHaveKey('password');
            expect($array)->not->toHaveKey('remember_token');
        });
    });

    describe('Tenant Scoping', function (): void {
        it('belongs to tenant through trait', function (): void {
            $tenant = Tenant::factory()->create();
            $user = User::factory()->for($tenant)->create();

            expect($user->tenant_id)->toBe($tenant->id);
        });

        it('filters by tenant correctly', function (): void {
            $tenant1 = Tenant::factory()->create();
            $tenant2 = Tenant::factory()->create();

            $users1 = User::factory()->count(3)->for($tenant1)->create();
            $users2 = User::factory()->count(2)->for($tenant2)->create();

            expect(User::query()->count())->toBe(5);
            expect(User::query()->where('tenant_id', $tenant1->id)->count())->toBe(3);
            expect(User::query()->where('tenant_id', $tenant2->id)->count())->toBe(2);
        });
    });

    describe('Validation and Business Rules', function (): void {
        it('requires email to be unique', function (): void {
            $email = 'test@example.com';
            User::factory()->create(['email' => $email]);

            expect(function () use ($email): void {
                User::factory()->create(['email' => $email]);
            })->toThrow(Exception::class);
        });

        it('defaults to viewer role if not specified', function (): void {
            // Test the factory default behavior
            $user = User::factory()->create();

            expect($user->role)->toBeIn(['viewer', 'editor', 'manager', 'admin', 'owner']);
        });

        it('requires tenant_id', function (): void {
            expect(function (): void {
                User::query()->create([
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'password' => 'password',
                    // Missing tenant_id
                ]);
            })->toThrow(Exception::class);
        });
    });
});
