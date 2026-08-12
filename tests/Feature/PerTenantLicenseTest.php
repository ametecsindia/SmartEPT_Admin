<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InstallationLicense;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-tenant licensing on the shared cloud install (12-Aug-2026).
 *
 * AMETECS_SAAS companies carry their OWN licence row; everyone else keeps the
 * single install-level row (client-hosted behaviour unchanged). Central is
 * always faked — no real phone-home from tests.
 */
class PerTenantLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['smartept.license_url' => 'https://central.fake']);
    }

    private function makeSaasCompany(string $name = 'Khan Incorporation'): Company
    {
        return Company::create([
            'name' => $name,
            'code' => strtoupper(substr(md5($name), 0, 8)),
            'slug' => strtolower(str_replace(' ', '', $name)),
            'deployment_model' => 'AMETECS_SAAS',
            'status' => 'ACTIVE',
            'external_tenant_id' => '77',
        ]);
    }

    private function makeCompanyAdmin(Company $company, string $email): User
    {
        return User::create([
            'name' => 'Tenant Admin',
            'email' => $email,
            'password' => 'password',
            'company_id' => $company->id,
            'role_id' => Role::where('slug', 'COMPANY_ADMIN')->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email, 'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_governing_row_is_own_for_saas_and_install_for_everyone_else(): void
    {
        $saas = $this->makeSaasCompany();
        $install = InstallationLicense::current();

        $own = InstallationLicense::governing($saas);
        $this->assertSame($saas->id, $own->company_id);
        $this->assertNotSame($install->id, $own->id);

        $other = Company::where('deployment_model', '!=', 'AMETECS_SAAS')->orWhereNull('deployment_model')->first()
            ?? Company::create(['name' => 'Plain Co', 'code' => 'PLAIN1']);
        $this->assertSame($install->id, InstallationLicense::governing($other)->id);
        $this->assertSame($install->id, InstallationLicense::governing(null)->id);
    }

    public function test_saas_company_admin_manages_their_own_licence_not_the_installs(): void
    {
        Http::fake(['central.fake/*' => Http::response(['ok' => true, 'bundle' => [
            'key' => 'SEPT-KHAN-KHAN-KHAN-KHAN', 'company' => 'Khan Incorporation',
            'plan' => 'smartept', 'kind' => 'subscription', 'deployment' => 'cloud',
            'device_limit' => 25, 'status' => 'active',
            'expires_at' => now()->addYear()->toDateString(), 'grace_days' => 7,
        ]])]);

        $saas = $this->makeSaasCompany();
        $this->makeCompanyAdmin($saas, 'khanadmin@example.com');
        $token = $this->login('khanadmin@example.com');

        $res = $this->withToken($token)
            ->postJson('/api/license', ['key' => 'SEPT-KHAN-KHAN-KHAN-KHAN'])->assertOk();

        $this->assertSame('company', $res->json('scope'));
        $this->assertSame('active', $res->json('status'));

        $row = InstallationLicense::where('company_id', $saas->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('SEPT-KHAN-KHAN-KHAN-KHAN', $row->license_key);

        // The install-level slot stays untouched — the whole point of the change.
        $this->assertNull(InstallationLicense::current()->license_key);

        // And their Licence screen reads back their own row, marked as company-scoped.
        $show = $this->withToken($token)->getJson('/api/license')->assertOk();
        $this->assertSame('company', $show->json('scope'));
        $this->assertSame('active', $show->json('status'));
    }

    public function test_saas_admin_cannot_import_offline_licence_file(): void
    {
        $saas = $this->makeSaasCompany();
        $this->makeCompanyAdmin($saas, 'khanadmin@example.com');
        $token = $this->login('khanadmin@example.com');

        $this->withToken($token)
            ->postJson('/api/license/import', ['token' => 'whatever'])
            ->assertStatus(403);
    }

    public function test_provision_delivers_the_tenant_licence_key_automatically(): void
    {
        config(['services.provision.secret' => 'test-secret']);
        Http::fake(['central.fake/*' => Http::response(['ok' => true, 'bundle' => [
            'company' => 'Fresh Tenant', 'plan' => 'smartept', 'kind' => 'subscription',
            'deployment' => 'cloud', 'device_limit' => 10, 'status' => 'active',
            'expires_at' => now()->addYear()->toDateString(), 'grace_days' => 7,
        ]])]);

        $res = $this->withHeaders(['X-Provision-Secret' => 'test-secret'])
            ->postJson('/api/provision', [
                'external_tenant_id' => '901',
                'company_name' => 'Fresh Tenant',
                'admin_email' => 'fresh@example.com',
                'licence_key' => 'SEPT-FRSH-FRSH-FRSH-FRSH',
            ])->assertCreated();

        $company = Company::where('external_tenant_id', '901')->firstOrFail();
        $row = InstallationLicense::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('SEPT-FRSH-FRSH-FRSH-FRSH', $row->license_key);
        $this->assertSame('active', $row->status);
        $this->assertNull(InstallationLicense::current()->license_key);
        $this->assertSame($company->slug, $res->json('slug'));
    }

    public function test_tenants_screen_is_super_only_and_rolls_up_per_tenant_licences(): void
    {
        $saas = $this->makeSaasCompany();
        InstallationLicense::forCompany($saas->id)->forceFill([
            'license_key' => 'SEPT-KHAN-KHAN-KHAN-KHAN', 'status' => 'active',
            'bundle' => ['company' => 'Khan Incorporation', 'plan' => 'smartept', 'device_limit' => 25, 'grace_days' => 7],
        ])->save();

        // Company admin: no.
        $this->makeCompanyAdmin($saas, 'khanadmin@example.com');
        $this->withToken($this->login('khanadmin@example.com'))
            ->getJson('/api/tenants')->assertStatus(403);

        // Super admin: full rollup, tenant carries its OWN licence scope.
        $res = $this->withToken($this->login('super@smartept.io'))
            ->getJson('/api/tenants')->assertOk();

        $khan = collect($res->json('data'))->firstWhere('name', 'Khan Incorporation');
        $this->assertNotNull($khan);
        $this->assertTrue($khan['is_cloud_tenant']);
        $this->assertSame('own', $khan['licence']['scope']);
        $this->assertSame('active', $khan['licence']['status']);
        $this->assertSame(25, $khan['licence']['device_limit']);

        $other = collect($res->json('data'))->firstWhere('is_cloud_tenant', false);
        if ($other) {
            $this->assertSame('install', $other['licence']['scope']);
        }
    }
}
