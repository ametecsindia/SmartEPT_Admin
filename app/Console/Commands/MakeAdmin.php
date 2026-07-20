<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * On-premise bootstrap: create (or reset) an admin login so a fresh install can be
 * signed into. Ensures roles exist, finds/creates a company, and mints a COMPANY_ADMIN
 * (or SUPER_ADMIN) with a temporary password the operator must change at first login.
 *
 *   php artisan smartept:make-admin admin@acme.com --company="Acme Pvt Ltd"
 */
class MakeAdmin extends Command
{
    protected $signature = 'smartept:make-admin
        {email : Login email for the admin}
        {--name=Administrator : Display name}
        {--company= : Company name (created if new; otherwise the first company is used)}
        {--password= : Set a specific password (default: a random temporary one is generated)}
        {--super : Create a SUPER_ADMIN instead of a COMPANY_ADMIN}';

    protected $description = 'Create or reset an admin login for the on-premise SmartEPT console.';

    public function handle(): int
    {
        // 1) Make sure the role catalogue exists (idempotent).
        if (! Role::whereNull('company_id')->where('slug', 'COMPANY_ADMIN')->exists()) {
            $this->info('Seeding roles & permissions...');
            $this->callSilent('db:seed', [
                '--class' => 'Database\\Seeders\\RolePermissionSeeder',
                '--force' => true,
            ]);
        }

        $slug = $this->option('super') ? 'SUPER_ADMIN' : 'COMPANY_ADMIN';
        $role = Role::whereNull('company_id')->where('slug', $slug)->first();
        if (! $role) {
            $this->error("Role {$slug} is still missing after seeding — run the RolePermissionSeeder.");
            return self::FAILURE;
        }

        // 2) Company (COMPANY_ADMIN only; a SUPER_ADMIN is not tied to a tenant).
        $companyId = null;
        if (! $this->option('super')) {
            $name = trim((string) $this->option('company'));
            if ($name !== '') {
                $company = Company::updateOrCreate(
                    ['name' => $name],
                    ['code' => $this->companyCode($name), 'status' => 'ACTIVE',
                     'timezone' => 'Asia/Kolkata', 'deployment_model' => 'LAN'],
                );
            } else {
                $company = Company::query()->orderBy('id')->first()
                    ?? Company::create(['name' => 'My Company', 'code' => 'MYCO', 'status' => 'ACTIVE',
                                        'timezone' => 'Asia/Kolkata', 'deployment_model' => 'LAN']);
            }
            $companyId = $company->id;
        }

        // 3) The admin user (password hashed by the model cast).
        $password = (string) ($this->option('password') ?: Str::password(12));
        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            [
                'name'                 => $this->option('name'),
                'password'             => $password,
                'role_id'              => $role->id,
                'company_id'           => $companyId,
                'status'               => 'ACTIVE',
                'must_change_password' => true,
            ],
        );

        $this->newLine();
        $this->info('Admin login ready:');
        $this->line('  Email:    ' . $user->email);
        $this->line('  Password: ' . $password);
        $this->line('  Role:     ' . $slug . ($companyId ? '  (company #' . $companyId . ')' : ''));
        $this->newLine();
        $this->warn('You will be asked to set a new password at first login.');

        return self::SUCCESS;
    }

    private function companyCode(string $name): string
    {
        $base = strtoupper(Str::slug(Str::of($name)->limit(10, ''), '')) ?: 'CO';
        $code = $base;
        $i = 1;
        while (Company::where('code', $code)->exists()) {
            $code = substr($base, 0, 56) . '-' . $i++;
        }
        return $code;
    }
}
