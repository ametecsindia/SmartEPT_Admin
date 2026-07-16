<?php

namespace Tests\Feature;

use App\Models\StorageFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-4 ops: audit viewer (scoped + filterable), storage usage rollup,
 * backup command writes a restorable gzipped dump + retention pruning.
 */
class M12OpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email, 'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_audit_log_viewer_lists_and_filters(): void
    {
        $admin = $this->login('admin@ametecs.io'); // the login itself writes a LOGIN audit row

        $res = $this->withToken($admin)->getJson('/api/audit-logs?action=LOGIN')->assertOk();
        $this->assertGreaterThan(0, $res->json('total'));
        $this->assertSame('LOGIN', $res->json('data.0.action'));

        // Employees may not read the trail.
        $emp = $this->login('priya.raman@ametecs.io');
        $this->withToken($emp)->getJson('/api/audit-logs')->assertStatus(403);
    }

    public function test_storage_usage_rolls_up_per_company(): void
    {
        $admin = $this->login('admin@ametecs.io');
        $companyId = \App\Models\User::where('email', 'admin@ametecs.io')->first()->company_id;

        StorageFile::create([
            'company_id' => $companyId,
            'file_type' => 'SCREENSHOT', 'storage_driver' => 'local', 'storage_key' => 'x/a.jpg', 'size_bytes' => 2048,
        ]);
        StorageFile::create([
            'company_id' => $companyId,
            'file_type' => 'SCREENSHOT', 'storage_driver' => 'local', 'storage_key' => 'x/b.jpg', 'size_bytes' => 1024,
        ]);

        $res = $this->withToken($admin)->getJson('/api/ops/storage-usage')->assertOk();
        $row = collect($res->json('data'))->firstWhere('company_id', $companyId);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['files']);
        $this->assertSame(3072, $row['bytes']);
    }

    public function test_backup_command_writes_gzip_and_prunes(): void
    {
        $dir = storage_path('app/backups');
        array_map('unlink', glob($dir . '/smartept-backup-*.sql.gz') ?: []);

        $this->artisan('smartept:backup-database', ['--keep' => 1])->assertSuccessful();

        $files = glob($dir . '/smartept-backup-*.sql.gz');
        $this->assertCount(1, $files);

        $sql = (string) gzdecode(file_get_contents($files[0]));
        $this->assertStringContainsString('INSERT INTO `users`', $sql);
        $this->assertStringContainsString('INSERT INTO `employees`', $sql);

        // Second run with keep=1 prunes the first file.
        $this->travelTo(now()->addMinute());
        $this->artisan('smartept:backup-database', ['--keep' => 1])->assertSuccessful();
        $this->assertCount(1, glob($dir . '/smartept-backup-*.sql.gz'));

        array_map('unlink', glob($dir . '/smartept-backup-*.sql.gz') ?: []);
    }

    public function test_backup_endpoint_runs_and_lists(): void
    {
        $admin = $this->login('admin@ametecs.io');

        $this->withToken($admin)->postJson('/api/ops/backup')->assertOk()
            ->assertJsonPath('ok', true);

        $list = $this->withToken($admin)->getJson('/api/ops/backups')->assertOk();
        $this->assertGreaterThan(0, count($list->json('data')));

        array_map('unlink', glob(storage_path('app/backups') . '/smartept-backup-*.sql.gz') ?: []);
    }
}
