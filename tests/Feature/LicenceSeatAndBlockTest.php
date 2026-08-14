<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\InstallationLicense;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Licence enforcement (Ejaz's findings, 14-Aug-2026).
 *
 *  1.4  "2 registered / 1 licensed" is now a RULE, not a label — people, logins
 *       and PCs stop at the licensed count.
 *  1.5  Expiry + grace over = the console stops. A Company Admin keeps the way
 *       in to the Licence screen so a new key can always be entered.
 *
 * Central is never really called: the licence row is pre-filled and stamped
 * fresh, and Http is faked as a backstop.
 */
class LicenceSeatAndBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['smartept.license_url' => 'https://central.fake', 'smartept.licence_enforce' => true]);
        Http::fake(['central.fake/*' => Http::response(['ok' => true])]);
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'Seat Co', 'code' => 'SEATCO', 'slug' => 'seatco',
            'deployment_model' => 'AMETECS_SAAS', 'status' => 'ACTIVE',
        ]);
    }

    /** Give the company its own licence row with a known bundle, checked in just now. */
    private function licence(Company $company, array $bundle, string $status = 'active'): InstallationLicense
    {
        $licence = InstallationLicense::forCompany($company->id);
        $licence->forceFill([
            'license_key' => 'SEPT-TEST-TEST-TEST-TEST',
            'status' => $status,
            'bundle' => $bundle + ['grace_days' => 7, 'device_limit' => 2],
            'last_checked_at' => now(),
        ])->save();

        return $licence;
    }

    private function user(Company $company, string $role, string $email): User
    {
        return User::create([
            'name' => $role, 'email' => $email, 'password' => 'password',
            'company_id' => $company->id,
            'role_id' => Role::where('slug', $role)->whereNull('company_id')->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    private function login(string $email)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password']);
    }

    private function addEmployee(string $token, string $code)
    {
        return $this->withToken($token)->postJson('/api/employees', [
            'employee_code' => $code,
            'first_name' => 'Person ' . $code,
            'create_login' => false,
        ]);
    }

    // ---- 1.4 seats -------------------------------------------------------

    public function test_employees_stop_at_the_licensed_seat_count(): void
    {
        $company = $this->company();
        $this->licence($company, ['device_limit' => 2, 'expires_at' => now()->addYear()->toDateString()]);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        $this->addEmployee($token, 'E1')->assertCreated();
        $this->addEmployee($token, 'E2')->assertCreated();

        $this->addEmployee($token, 'E3')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LICENSE_SEAT_LIMIT');

        $this->assertSame(2, Employee::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_a_freed_seat_can_be_used_again(): void
    {
        $company = $this->company();
        $this->licence($company, ['device_limit' => 2, 'expires_at' => now()->addYear()->toDateString()]);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        $this->addEmployee($token, 'E1')->assertCreated();
        $this->addEmployee($token, 'E2')->assertCreated();
        $this->addEmployee($token, 'E3')->assertStatus(409);

        // Relieving someone hands their seat back.
        Employee::withoutGlobalScopes()->where('employee_code', 'E2')
            ->update(['employment_status' => 'RELIEVED']);

        $this->addEmployee($token, 'E3')->assertCreated();
    }

    public function test_employee_logins_consume_a_seat_but_admin_logins_do_not(): void
    {
        $company = $this->company();
        $this->licence($company, ['device_limit' => 1, 'expires_at' => now()->addYear()->toDateString()]);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        // An HR login is an operator of the system — free.
        $this->withToken($token)->postJson('/api/users', [
            'name' => 'HR', 'email' => 'hr@seatco.test', 'role' => 'HR_ADMIN',
        ])->assertCreated();

        // The first EMPLOYEE login takes the single seat; the second is refused.
        $this->withToken($token)->postJson('/api/users', [
            'name' => 'Emp One', 'email' => 'emp1@seatco.test', 'role' => 'EMPLOYEE',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/users', [
            'name' => 'Emp Two', 'email' => 'emp2@seatco.test', 'role' => 'EMPLOYEE',
        ])->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_SEAT_LIMIT');
    }

    public function test_no_cap_while_the_install_is_still_unlicensed(): void
    {
        $company = $this->company();
        // No licence row written at all — the 7-day evaluation, nothing to cap against.
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        foreach (['A', 'B', 'C', 'D'] as $code) {
            $this->addEmployee($token, $code)->assertCreated();
        }
    }

    public function test_bulk_import_stops_at_the_licensed_seat_count(): void
    {
        $company = $this->company();
        $this->licence($company, ['device_limit' => 2, 'expires_at' => now()->addYear()->toDateString()]);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        $res = $this->withToken($token)->postJson('/api/employees/bulk-import', [
            'create_login' => false,
            'rows' => [
                ['employee_code' => 'B1', 'first_name' => 'One'],
                ['employee_code' => 'B2', 'first_name' => 'Two'],
                ['employee_code' => 'B3', 'first_name' => 'Three'],
                ['employee_code' => 'B4', 'first_name' => 'Four'],
            ],
        ])->assertOk();

        $this->assertSame(2, $res->json('summary.created'));
        $this->assertSame(2, $res->json('summary.failed'));
        $this->assertStringContainsString('seat limit', strtolower((string) $res->json('results.2.error')));
        $this->assertSame(2, Employee::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_a_relieved_employee_cannot_be_reactivated_past_the_cap(): void
    {
        $company = $this->company();
        $this->licence($company, ['device_limit' => 1, 'expires_at' => now()->addYear()->toDateString()]);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        $this->addEmployee($token, 'E1')->assertCreated();
        $relieved = Employee::create([
            'company_id' => $company->id, 'employee_code' => 'E2', 'first_name' => 'Gone',
            'employment_status' => 'RELIEVED',
        ]);

        $this->withToken($token)->putJson('/api/employees/' . $relieved->id, [
            'employment_status' => 'ACTIVE',
        ])->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_SEAT_LIMIT');
    }

    public function test_an_admin_login_cannot_be_switched_to_employee_past_the_cap(): void
    {
        $company = $this->company();
        $this->licence($company, ['device_limit' => 1, 'expires_at' => now()->addYear()->toDateString()]);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');
        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/users', [
            'name' => 'Emp One', 'email' => 'emp1@seatco.test', 'role' => 'EMPLOYEE',
        ])->assertCreated();

        // Free role today…
        $hr = $this->withToken($token)->postJson('/api/users', [
            'name' => 'HR', 'email' => 'hr@seatco.test', 'role' => 'HR_ADMIN',
        ])->assertCreated()->json('data.id');

        // …must not become a seat-consuming EMPLOYEE tomorrow.
        $this->withToken($token)->putJson('/api/users/' . $hr, ['role' => 'EMPLOYEE'])
            ->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_SEAT_LIMIT');
    }

    // ---- 1.5 expiry + grace ----------------------------------------------

    private function deadLicence(Company $company): void
    {
        $this->licence($company, [
            'device_limit' => 25,
            'expires_at' => now()->subDays(30)->toDateString(),
            'grace_days' => 7,
        ], 'expired');
    }

    public function test_an_employee_cannot_sign_in_once_expiry_and_grace_have_passed(): void
    {
        $company = $this->company();
        $this->deadLicence($company);

        $emp = Employee::create([
            'company_id' => $company->id, 'employee_code' => 'E1', 'first_name' => 'Blocked',
            'employment_status' => 'ACTIVE',
        ]);
        $user = $this->user($company, 'EMPLOYEE', 'emp@seatco.test');
        $emp->update(['user_id' => $user->id]);

        $this->login('emp@seatco.test')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_BLOCKED');
    }

    public function test_inside_the_grace_window_everyone_still_works(): void
    {
        $company = $this->company();
        $this->licence($company, [
            'device_limit' => 25,
            'expires_at' => now()->subDays(2)->toDateString(),
            'grace_days' => 7,
        ], 'expired');

        $emp = Employee::create([
            'company_id' => $company->id, 'employee_code' => 'E1', 'first_name' => 'Still Working',
            'employment_status' => 'ACTIVE',
        ]);
        $user = $this->user($company, 'EMPLOYEE', 'emp@seatco.test');
        $emp->update(['user_id' => $user->id]);

        $this->login('emp@seatco.test')->assertOk();
    }

    public function test_the_company_admin_keeps_the_way_in_to_the_licence_screen(): void
    {
        $company = $this->company();
        $this->deadLicence($company);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');

        $token = $this->login('admin@seatco.test')->assertOk()->json('token');

        // The rest of the console is closed…
        $this->withToken($token)->getJson('/api/employees')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_BLOCKED')
            ->assertJsonPath('error.admin_can_fix', true);

        // …but the rescue route is open, so a new key can always be entered.
        $this->withToken($token)->getJson('/api/license')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_enforcement_can_be_switched_off_for_internal_installs(): void
    {
        config(['smartept.licence_enforce' => false]);

        $company = $this->company();
        $this->deadLicence($company);
        $this->user($company, 'COMPANY_ADMIN', 'admin@seatco.test');

        $token = $this->login('admin@seatco.test')->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/employees')->assertOk();
    }
}
