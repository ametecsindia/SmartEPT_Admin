<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * 25-Sep-2026 (Ejaz): every card on every console screen must be in the role matrix
 * (Organisation → Roles, Card | View | Edit). These were missing. Each existing role is
 * given exactly the access it already has to them today, so nothing changes until an
 * admin changes a tick:
 *  - a card split out of an existing card inherits that card's View/Edit ticks;
 *  - a card that was only ever governed by the old role lists gets the ticks those
 *    lists allowed for the role (its base role, for a custom role).
 * Roles with no card ticks at all are left alone (they keep their old access).
 * Super Admin / Company Admin get everything, as with every other card.
 */
return new class extends Migration
{
    /**
     * [tab key, group label, card key, card label, editable,
     *  inherit-from card (or null), legacy View roles, legacy Edit roles]
     */
    private array $cards = [
        ['webcam', 'Webcam', 'webcam_detected', 'Webcam presence detected', false, 'webcam.webcam_photos', [], []],
        ['org', 'Organisation', 'org_units', 'Branches, departments, teams, designations & shifts', true, null,
            ['BRANCH_ADMIN', 'HR_ADMIN', 'MANAGER', 'AUDITOR'], ['BRANCH_ADMIN', 'HR_ADMIN']],
        ['devices', 'Devices', 'agent_lock', 'Agent exit & uninstall lock', true, null, [], []],
        ['rules', 'App & Web Rules', 'enforcement', 'Enforcement + who is enforced, and why', true, 'rules.app_web_rules', [], []],
        ['rules', 'App & Web Rules', 'device_control', 'Device control (USB, camera, uploads, media)', true, 'rules.app_web_rules', [], []],
        ['biometric', 'Biometric', 'gate_to_pc', 'Gate-to-PC', true, 'biometric.bio_setup', [], []],
        ['gateexcl', 'Gate Exclusions', 'gate_status', 'Gate-to-PC status', false, null,
            ['BRANCH_ADMIN', 'HR_ADMIN'], []],
        ['gateexcl', 'Gate Exclusions', 'exclusions', 'Standing exclusions', true, null,
            ['BRANCH_ADMIN', 'HR_ADMIN'], ['BRANCH_ADMIN', 'HR_ADMIN']],
        ['reports', 'Reports & Exports', 'rep_monthly', 'Monthly summary', false, 'reports.*', [], []],
        ['ops', 'Data & Ops', 'storage_quota', 'Storage quota', false, null, [], []],
        ['ops', 'Data & Ops', 'mail_smtp', 'Email / SMTP', true, null, [], []],
        ['ops', 'Data & Ops', 'notifications', 'Notifications', true, null, [], []],
    ];

    public function up(): void
    {
        $new = []; // slug => id
        foreach ($this->cards as [$tab, $group, $key, $label, $editable]) {
            $new["card.$tab.$key.view"] = Permission::updateOrCreate(
                ['slug' => "card.$tab.$key.view"], ['name' => $label, 'group' => 'Card access · ' . $group])->id;
            if ($editable) {
                $new["card.$tab.$key.edit"] = Permission::updateOrCreate(
                    ['slug' => "card.$tab.$key.edit"], ['name' => $label . ' (edit)', 'group' => 'Card access · ' . $group])->id;
            }
        }

        foreach (Role::with('permissions:id,slug')->get() as $role) {
            $held = $role->permissions->pluck('slug')->all();

            if (in_array($role->slug, ['SUPER_ADMIN', 'COMPANY_ADMIN'], true)) {
                $role->permissions()->syncWithoutDetaching(array_values($new));
                continue;
            }
            if (! preg_grep('/^card\./', $held)) {
                continue; // never set up in the matrix: keeps its old access
            }

            $base = $role->base_slug ?: $role->slug;
            $grant = [];
            foreach ($this->cards as [$tab, , $key, , $editable, $from, $legacyView, $legacyEdit]) {
                if ($from) {
                    $prefix = 'card.' . rtrim($from, '*');
                    $has = fn (string $lv) => (bool) array_filter($held, fn ($h) => str_starts_with($h, $prefix)
                        && (str_ends_with($h, '.' . $lv) || ($lv === 'view' && str_ends_with($h, '.edit'))));
                    $view = $has('view');
                    $edit = $has('edit');
                } else {
                    $edit = in_array($base, $legacyEdit, true);
                    $view = $edit || in_array($base, $legacyView, true);
                }
                if ($view) {
                    $grant[] = $new["card.$tab.$key.view"];
                }
                if ($edit && $editable) {
                    $grant[] = $new["card.$tab.$key.edit"];
                }
            }
            $role->permissions()->syncWithoutDetaching($grant);
        }
    }

    public function down(): void
    {
        $slugs = [];
        foreach ($this->cards as [$tab, , $key]) {
            $slugs[] = "card.$tab.$key.view";
            $slugs[] = "card.$tab.$key.edit";
        }
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        foreach (Role::all() as $role) {
            $role->permissions()->detach($ids);
        }
        Permission::whereIn('id', $ids)->delete();
    }
};
