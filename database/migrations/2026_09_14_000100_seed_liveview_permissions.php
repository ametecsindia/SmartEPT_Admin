<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * LiveView Phase 4 (14-Sep-2026): replaces the Phase 3 POC's blanket
 * role:SUPER_ADMIN,COMPANY_ADMIN gate with its own permission slugs, same
 * shape as the meeting.* precedent (MeetingController's routes + admin.blade.php's
 * can('meeting.schedule') etc.). Every existing role keeps its current access —
 * granted every slug here — so nothing changes for today's users until an admin
 * deliberately edits a role (same "preserve current behaviour" rule the card-access
 * migration used, see 2026_08_02_000100_seed_card_view_edit_permissions.php).
 */
return new class extends Migration
{
    /** [slug, name] */
    private array $slugs = [
        ['liveview.view', 'LiveView — see the nav item / open the panel'],
        ['liveview.start', 'LiveView — start a session'],
        ['liveview.stop', 'LiveView — stop a session'],
        ['liveview.high_quality', 'LiveView — choose High (1080p) quality'],
    ];

    public function up(): void
    {
        $ids = [];
        foreach ($this->slugs as [$slug, $name]) {
            $ids[] = Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => 'LiveView'])->id;
        }

        foreach (Role::all() as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        $ids = Permission::where('slug', 'like', 'liveview.%')->pluck('id')->all();
        foreach (Role::all() as $role) {
            $role->permissions()->detach($ids);
        }
        Permission::where('slug', 'like', 'liveview.%')->delete();
    }
};
