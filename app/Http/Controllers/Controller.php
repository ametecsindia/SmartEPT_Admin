<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Write an admin-action audit record. Used across admin controllers so every
     * create/update/delete/assign/export is accountable (SmartEPT audit requirement).
     */
    protected function audit(Request $request, string $action, ?string $subjectType = null, $subjectId = null, ?array $changes = null, ?\App\Models\User $actor = null): void
    {
        // $actor covers pre-auth actions (LOGIN) where $request->user() is still null —
        // without it those rows were anonymous and invisible to the tenant-scoped viewer.
        $user = $actor ?? $request->user();

        AuditLog::create([
            'company_id'   => $user?->company_id,
            'user_id'      => $user?->id,
            'action'       => $action,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'changes'      => $changes,
            'ip'           => $request->ip(),
            'user_agent'   => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
