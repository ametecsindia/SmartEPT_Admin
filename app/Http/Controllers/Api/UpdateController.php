<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UpdateClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Check for Update" on the Licence screen (Ejaz, 1-Sep-2026).
 *
 * Four steps, each its own call so the console can show honest progress and a
 * failure can never be mistaken for a success: check → download → install →
 * status. Restricted to SUPER_ADMIN / COMPANY_ADMIN by the route group.
 */
class UpdateController extends Controller
{
    public function __construct(private UpdateClient $updates)
    {
    }

    /** GET /api/update — what this server is running and what it last found. */
    public function status(): JsonResponse
    {
        $state = $this->updates->state();

        return response()->json([
            'ok'              => true,
            'current_version' => $this->updates->currentVersion(),
            'phase'           => $state['phase'],
            'percent'         => $state['percent'],
            'message'         => $state['message'],
            'available'       => $state['available'],
            'checked_at'      => $state['checked_at'],
            'log'             => array_slice((array) $state['log'], -20),
            'can_install'     => $this->updates->phpBinary() !== null && $this->updates->canSpawn(),
        ]);
    }

    /** POST /api/update/check */
    public function check(): JsonResponse
    {
        $result = $this->updates->check();

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 200);
    }

    /** POST /api/update/download — fetches and hash-verifies the package. */
    public function download(): JsonResponse
    {
        $result = $this->updates->download();

        return response()->json($result);
    }

    /**
     * POST /api/update/install — hands off to the standalone updater.
     *
     * The updater puts the app into maintenance mode, so the console cannot
     * poll /api for progress while it runs. It is given a one-time token and
     * polls public/update-status.php instead, which reads the same state file
     * without booting the application.
     */
    public function install(Request $request): JsonResponse
    {
        $state = $this->updates->state();
        $state['poll_token'] = Str::random(40);
        $this->updates->writeState($state);

        $result = $this->updates->install();

        return response()->json($result + [
            'poll_url' => url('/update-status.php?t=' . $state['poll_token']),
        ]);
    }
}
