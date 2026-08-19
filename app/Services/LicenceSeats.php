<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\InstallationLicense;
use App\Models\User;

/**
 * Seat accounting and enforcement for the licence governing a company.
 *
 * Ejaz, 14-Aug-2026 (finding 1.4): "Device seats: 2 registered / 1 licensed"
 * was only ever a LABEL — nothing stopped the client adding a fifteenth person
 * or a third PC on a one-seat licence. The rule is now enforced here and is the
 * single place the whole product asks "is there a free seat?".
 *
 * ONE LICENSED SEAT = ONE MONITORED PERSON = ONE PC.
 *   - employees : active (not relieved, not deleted) employee records
 *   - users     : active EMPLOYEE-role logins. Admin/HR/Manager logins are free —
 *                 they are operators of the system, not people being monitored.
 *   - devices   : bound agent machines (unbound ones have released their seat)
 *
 * Never counts across tenants: on the shared cloud install a tenant with its own
 * licence row is counted alone; a client-hosted install counts the whole server.
 *
 * Deliberately NOT retrospective — clients already over their seat count keep
 * every person and PC they have; they simply cannot add the next one.
 */
class LicenceSeats
{
    /** Master switch — the same one that gates EnsureLicensed (demo/internal installs). */
    public function enforced(): bool
    {
        return DevLicenceKey::enforcementOn();
    }

    /** [governing licence, company id to count by (null = whole install)] */
    private function scope(?int $companyId): array
    {
        $company = $companyId ? Company::find($companyId) : null;
        $licence = InstallationLicense::governing($company);

        return [$licence, $licence->company_id];
    }

    /** Seats sold on the governing licence. null = no key yet (evaluation) → no cap. */
    public function limit(?int $companyId): ?int
    {
        [$licence] = $this->scope($companyId);

        return $licence->configured() ? $licence->deviceLimit() : null;
    }

    public function employeesUsed(?int $companyId): int
    {
        [, $scopeId] = $this->scope($companyId);

        return Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('employment_status', 'ACTIVE')
            ->when($scopeId, fn ($q) => $q->where('company_id', $scopeId))
            ->count();
    }

    public function usersUsed(?int $companyId): int
    {
        [, $scopeId] = $this->scope($companyId);

        return User::withoutGlobalScopes()
            ->where('users.status', 'ACTIVE')
            ->when($scopeId, fn ($q) => $q->where('users.company_id', $scopeId))
            ->whereHas('role', fn ($q) => $q->where('slug', 'EMPLOYEE'))
            ->count();
    }

    public function devicesUsed(?int $companyId): int
    {
        [, $scopeId] = $this->scope($companyId);

        return EmployeeDevice::withoutGlobalScopes()
            ->whereNull('unbound_at')
            ->when($scopeId, fn ($q) => $q->where('company_id', $scopeId))
            ->count();
    }

    /** Everything the Licence screen and the daily report to Central need, in one query pass. */
    public function counts(?int $companyId): array
    {
        return [
            'limit' => $this->limit($companyId),
            'users' => $this->usersUsed($companyId),
            'employees' => $this->employeesUsed($companyId),
            'devices' => $this->devicesUsed($companyId),
        ];
    }

    /**
     * The gate. $what is 'employee' | 'user' | 'device'.
     * Returns NULL when the action may proceed, or the message to show the admin.
     */
    public function blockedReason(?int $companyId, string $what): ?string
    {
        if (! $this->enforced()) {
            return null;
        }

        $limit = $this->limit($companyId);
        if ($limit === null || $limit <= 0) {
            return null; // unlicensed / evaluation install — EnsureLicensed handles that case
        }

        $used = match ($what) {
            'employee' => $this->employeesUsed($companyId),
            'user' => $this->usersUsed($companyId),
            'device' => $this->devicesUsed($companyId),
            default => 0,
        };

        if ($used < $limit) {
            return null;
        }

        return match ($what) {
            'employee' => "Your licence covers {$limit} " . str('user')->plural($limit)
                . " and all {$limit} are in use. Relieve an employee you no longer track, or buy more users, then try again.",
            'user' => "Your licence covers {$limit} " . str('user')->plural($limit)
                . " and all {$limit} logins are in use. Disable a login you no longer need, or buy more users, then try again.",
            default => "All {$limit} licensed device " . str('seat')->plural($limit)
                . " are in use. Unbind a PC that has been replaced (Devices screen), or buy more users, then try again.",
        };
    }
}
