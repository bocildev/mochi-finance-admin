<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdminUser(): array
    {
        DB::table('finance_users')->insert([
            'sub' => 'usr_owner_maint',
            'name' => 'Owner Admin',
            'email' => 'athallahrich@gmail.com',
            'roles' => json_encode(['admin']),
            'permissions' => json_encode(['finance.admin', 'finance.basic']),
            'plan_tier' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'iam_user' => [
                'sub' => 'usr_owner_maint',
                'name' => 'Owner Admin',
                'email' => 'athallahrich@gmail.com',
                'roles' => ['admin'],
                'permissions' => ['finance.admin', 'finance.basic'],
                'plan_tier' => 'admin',
            ],
        ];
    }

    public function test_guest_and_non_admin_cannot_access_maintenance_console(): void
    {
        $this->get('/maintenance')->assertRedirect('/login');
        $this->post('/maintenance/run', ['command_key' => 'migrate_status'])->assertRedirect('/login');

        DB::table('finance_users')->insert([
            'sub' => 'usr_free_maint',
            'name' => 'Free User',
            'email' => 'free@example.com',
            'roles' => json_encode(['free_user']),
            'permissions' => json_encode(['finance.basic']),
            'plan_tier' => 'free',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $freeSession = [
            'iam_user' => [
                'sub' => 'usr_free_maint',
                'name' => 'Free User',
                'email' => 'free@example.com',
                'roles' => ['free_user'],
                'permissions' => ['finance.basic'],
                'plan_tier' => 'free',
            ],
        ];

        $this->withSession($freeSession)->get('/maintenance')->assertStatus(403);
        $this->withSession($freeSession)
            ->post('/maintenance/run', ['command_key' => 'migrate_status'])
            ->assertStatus(403);
    }

    public function test_admin_can_view_maintenance_console_and_run_allowlisted_commands(): void
    {
        $adminSession = $this->seedAdminUser();

        $this->withSession($adminSession)
            ->get('/maintenance')
            ->assertOk()
            ->assertSee('cPanel Maintenance & Debugger Console')
            ->assertSee('Database Migration Runner')
            ->assertSee('finance_recurring_bills');

        // Reject arbitrary / unallowlisted command keys
        $this->withSession($adminSession)
            ->from('/maintenance')
            ->post('/maintenance/run', ['command_key' => 'migrate:fresh'])
            ->assertRedirect('/maintenance')
            ->assertSessionHasErrors('command_key');

        // Reject destructive command without explicit confirmation checkbox
        $this->withSession($adminSession)
            ->from('/maintenance')
            ->post('/maintenance/run', ['command_key' => 'migrate_rollback'])
            ->assertRedirect('/maintenance')
            ->assertSessionHasErrors('maintenance');

        // Run migrate_status and verify terminal output flashed in session
        $this->withSession($adminSession)
            ->post('/maintenance/run', ['command_key' => 'migrate_status'])
            ->assertRedirect('/maintenance')
            ->assertSessionHas('maintenance_output', function ($out) {
                return ($out['key'] ?? null) === 'migrate_status'
                    && ($out['succeeded'] ?? false) === true
                    && ! empty($out['output']);
            });

        // Run route_list inspection
        $this->withSession($adminSession)
            ->post('/maintenance/run', ['command_key' => 'route_list'])
            ->assertRedirect('/maintenance')
            ->assertSessionHas('maintenance_output', function ($out) {
                return ($out['key'] ?? null) === 'route_list'
                    && ($out['succeeded'] ?? false) === true
                    && str_contains((string) ($out['output'] ?? ''), 'api/v1/bootstrap');
            });
    }
}
