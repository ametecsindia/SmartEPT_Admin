<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Company;
use App\Services\PolicyResolver;
use App\Support\EnforcementMode;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 27-Aug-2026 (Ejaz): "Need to have enforcement option available company wide (already available
 * in App/Web rules), update it for Branch, Department, Team and Shift level, so that this can be
 * applied or exempted appropriately."
 *
 * Branch, department, team, employee, device and company already resolved. SHIFT is the new
 * level, and the only interesting question about it is WHERE it sits — a hierarchy whose order
 * nobody can predict is worse than no hierarchy, because the console then explains a decision
 * the server did not make.
 *
 * It sits between EMPLOYEE and TEAM:
 *
 *     device -> employee -> SHIFT -> team -> department -> branch -> company
 *
 * Below the employee, because an exemption granted to one named person is a deliberate act
 * about that person and must survive them being rostered onto a different shift next week.
 * Above the team, because "the night shift may use the remote-support tool" is the request this
 * exists to serve, and it is worthless if the support team's setting outranks it.
 *
 * Every assertion here is one of those two sentences, made executable.
 */
class EnforcementHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // A person with every level populated, so each test only has to set the ONE column it
        // is about. Nothing carries an enforcement_mode yet — they all start on "inherit".
        $branch = Branch::withoutGlobalScopes()->create(['company_id' => 1, 'name' => 'Chennai']);
        $dept = Department::withoutGlobalScopes()->create(['company_id' => 1, 'branch_id' => $branch->id, 'name' => 'Collections']);
        $team = Team::withoutGlobalScopes()->create(['company_id' => 1, 'department_id' => $dept->id, 'name' => 'Voice']);
        $shift = Shift::withoutGlobalScopes()->create([
            'company_id' => 1, 'name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
        ]);

        $this->employee = Employee::withoutGlobalScopes()->where('employee_code', 'E-1001')->firstOrFail();
        $this->employee->forceFill([
            'branch_id' => $branch->id, 'department_id' => $dept->id,
            'team_id' => $team->id, 'shift_id' => $shift->id,
        ])->save();
    }

    private function mode(): string
    {
        return app(PolicyResolver::class)->effectiveEnforcementMode($this->employee->fresh());
    }

    private function set(string $model, string $value): void
    {
        $key = $model . '_id';
        $class = ['branch' => Branch::class, 'department' => Department::class,
            'team' => Team::class, 'shift' => Shift::class][$model];

        $class::withoutGlobalScopes()->whereKey($this->employee->{$key})
            ->update(['enforcement_mode' => $value]);
    }

    /** Nothing set anywhere is ENFORCED — an unset value must never be a quiet way out. */
    public function test_nothing_set_anywhere_resolves_to_enforced(): void
    {
        $this->assertSame(EnforcementMode::ENFORCED, $this->mode());
    }

    // ---- each level applies on its own -----------------------------------

    public function test_a_branch_can_exempt_everyone_in_it(): void
    {
        $this->set('branch', EnforcementMode::EXEMPT);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    public function test_a_department_can_exempt_everyone_in_it(): void
    {
        $this->set('department', EnforcementMode::EXEMPT);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    public function test_a_team_can_exempt_everyone_in_it(): void
    {
        $this->set('team', EnforcementMode::EXEMPT);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    /** The new one. */
    public function test_a_shift_can_exempt_everyone_on_it(): void
    {
        $this->set('shift', EnforcementMode::EXEMPT);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    // ---- and the order between them --------------------------------------

    /**
     * The request shift-level enforcement exists to serve. If the team wins here, the whole
     * feature is decorative — the night shift is enforced anyway and nobody can say why.
     */
    public function test_a_shift_exemption_beats_an_enforced_team(): void
    {
        $this->set('team', EnforcementMode::ENFORCED);
        $this->set('shift', EnforcementMode::EXEMPT);

        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    /** ...and the other way round: a shift may claw back an exemption granted higher up. */
    public function test_an_enforced_shift_beats_an_exempt_department(): void
    {
        $this->set('department', EnforcementMode::EXEMPT);
        $this->set('shift', EnforcementMode::ENFORCED);

        $this->assertSame(EnforcementMode::ENFORCED, $this->mode());
    }

    /**
     * The person outranks the roster. Somebody granted this exemption deliberately, about this
     * named individual; moving them to the night shift must not silently revoke it.
     */
    public function test_the_employees_own_setting_beats_their_shift(): void
    {
        $this->set('shift', EnforcementMode::ENFORCED);
        $this->employee->forceFill(['enforcement_mode' => EnforcementMode::EXEMPT])->save();

        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    /** Full chain, most specific first — each level beats the one below it. */
    public function test_the_whole_chain_resolves_most_specific_first(): void
    {
        Company::withoutGlobalScopes()->whereKey(1)->update(['enforcement_mode' => EnforcementMode::EXEMPT]);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode(), 'company');

        $this->set('branch', EnforcementMode::ENFORCED);
        $this->assertSame(EnforcementMode::ENFORCED, $this->mode(), 'branch beats company');

        $this->set('department', EnforcementMode::EXEMPT);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode(), 'department beats branch');

        $this->set('team', EnforcementMode::ENFORCED);
        $this->assertSame(EnforcementMode::ENFORCED, $this->mode(), 'team beats department');

        $this->set('shift', EnforcementMode::EXEMPT);
        $this->assertSame(EnforcementMode::EXEMPT, $this->mode(), 'shift beats team');

        $this->employee->forceFill(['enforcement_mode' => EnforcementMode::ENFORCED])->save();
        $this->assertSame(EnforcementMode::ENFORCED, $this->mode(), 'the person beats their shift');
    }

    /** A dated exemption on the person still beats everything, shift included. */
    public function test_a_live_dated_exemption_beats_an_enforced_shift(): void
    {
        $this->set('shift', EnforcementMode::ENFORCED);
        $this->employee->forceFill([
            'enforcement_mode' => EnforcementMode::ENFORCED,
            'enforcement_exempt_from' => now()->subDay()->toDateString(),
            'enforcement_exempt_until' => now()->addDay()->toDateString(),
            'enforcement_exempt_reason' => 'Covering client calls',
        ])->save();

        $this->assertSame(EnforcementMode::EXEMPT, $this->mode());
    }

    // ---- and the console can actually set it ------------------------------

    /**
     * The resolver reading a column nobody can write is a feature that does not exist. Each of
     * the four levels has to accept the field through the endpoint the console posts to.
     */
    public function test_the_org_endpoint_saves_enforcement_mode_at_every_level(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@ametecs.io', 'password' => 'password',
        ])->assertOk()->json('token');

        $ids = [
            'branches'    => $this->employee->branch_id,
            'departments' => $this->employee->department_id,
            'teams'       => $this->employee->team_id,
            'shifts'      => $this->employee->shift_id,
        ];

        foreach ($ids as $type => $id) {
            $this->withToken($token)
                ->putJson("/api/org/{$type}/{$id}", ['enforcement_mode' => EnforcementMode::EXEMPT])
                ->assertOk()
                ->assertJsonPath('data.enforcement_mode', EnforcementMode::EXEMPT);
        }

        // A junk value is refused rather than stored and later ignored.
        $this->withToken($token)
            ->putJson('/api/org/shifts/' . $ids['shifts'], ['enforcement_mode' => 'MAYBE'])
            ->assertStatus(422);
    }
}
