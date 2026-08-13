<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * ON-PREMISE CLIENT PROVISIONING — SmartPRS2 client:provision pattern, as-is
 * (13-Aug-2026). Run ONCE on the client's server after migrate + role seeding.
 * Creates the client's workspace CLEAN — their company, their admin login with
 * the password THEY chose — no demo data, no temp password, no email needed
 * (client servers often have no SMTP yet). Idempotent: refuses to run twice.
 *
 *   php artisan smartept:client-provision --company="ABC Recoveries Pvt Ltd"
 *       --email=admin@abcrecoveries.in --password=TheirStrongPassword
 *       [--name="Mr. Sharma"]
 */
class ClientProvision extends Command
{
    protected $signature = 'smartept:client-provision
        {--company= : The client company name}
        {--email=   : The admin login email}
        {--password= : The admin login password (min 8 characters)}
        {--name=    : Admin display name (default: Administrator)}';

    protected $description = 'Provision a clean on-premise client workspace (company + admin login, no demo data)';

    public function handle(): int
    {
        $company = trim((string) $this->option('company'));
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');
        $adminName = trim((string) $this->option('name')) ?: 'Administrator';

        if ($company === '' || $email === '' || $password === '') {
            $this->error('Usage: php artisan smartept:client-provision --company="..." --email=... --password=...');

            return self::FAILURE;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That admin email does not look valid: ' . $email);

            return self::FAILURE;
        }
        if (strlen($password) < 8) {
            $this->error('Please use a password of at least 8 characters.');

            return self::FAILURE;
        }
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('A user with that email already exists — this server looks already provisioned.');

            return self::FAILURE;
        }

        // Role catalogue must exist (roles-only seeding — never the demo seeder).
        if (! Role::whereNull('company_id')->where('slug', 'COMPANY_ADMIN')->exists()) {
            $this->info('Seeding roles & permissions...');
            $this->callSilent('db:seed', [
                '--class' => 'Database\\Seeders\\RolePermissionSeeder',
                '--force' => true,
            ]);
        }
        $role = Role::whereNull('company_id')->where('slug', 'COMPANY_ADMIN')->first();
        if (! $role) {
            $this->error('COMPANY_ADMIN role is missing after seeding — run the RolePermissionSeeder.');

            return self::FAILURE;
        }

        $co = Company::create([
            'name' => $company,
            'code' => $this->companyCode($company),
            'timezone' => 'Asia/Kolkata',
            'deployment_model' => 'LAN',
            'status' => 'ACTIVE',
        ]);

        User::create([
            'name' => $adminName,
            'email' => $email,
            'password' => $password, // hashed once by the model cast — the client's own choice
            'role_id' => $role->id,
            'company_id' => $co->id,
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);

        $this->newLine();
        $this->info('Client workspace provisioned:');
        $this->line('  Company: ' . $co->name);
        $this->line('  Admin:   ' . $email . '  (the password you just chose)');
        $this->newLine();
        $this->line('Next: open /admin to sign in. The 7-day evaluation is running;');
        $this->line('license via /activate (machine fingerprint -> Ametecs -> upload the .lic).');

        return self::SUCCESS;
    }

    private function companyCode(string $name): string
    {
        $base = strtoupper(Str::slug(Str::of($name)->limit(10, ''), '')) ?: 'CO';
        $code = $base;
        $i = 1;
        while (Company::where('code', $code)->exists()) {
            $code = $base . $i++;
        }

        return $code;
    }
}
