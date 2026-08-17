<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\IntegrationTarget;
use App\Services\OutboundPusher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin management of the integration hub (Ejaz 17-Jul): create/revoke API keys
 * for inbound access, and manage outbound push targets (SmartPRS etc.).
 * Company-admin only; every action is audit-logged.
 */
class IntegrationController extends Controller
{
    // ---------- API keys ----------

    public function keys(Request $request): JsonResponse
    {
        $rows = ApiKey::where('company_id', $request->user()->company_id)->latest('id')->get()
            ->map(fn ($k) => [
                'id' => $k->id, 'name' => $k->name, 'prefix' => $k->prefix,
                'scopes' => $k->scopes, 'active' => $k->active,
                'expires_at' => optional($k->expires_at)->toDateString(),
                'expired' => (bool) ($k->expires_at && $k->expires_at->isPast()),
                'allowed_ips' => $k->allowed_ips,
                'last_used_at' => optional($k->last_used_at)->toDateTimeString(),
                'created_at' => $k->created_at->toDateString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function createKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['in:ingest,read'],
            // A bridge key lives in a config file on a customer PC: give it an
            // expiry and, where the site has a fixed WAN address, an allow-list.
            'expires_at' => ['nullable', 'date', 'after:today'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['string', 'max:64'],
        ]);

        // The full secret is shown exactly once; only its hash is stored.
        $secret = 'sk_live_' . Str::random(40);
        $prefix = substr($secret, 0, 12);

        $key = ApiKey::create([
            'company_id' => $request->user()->company_id,
            'name' => $data['name'],
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $secret),
            'scopes' => $data['scopes'] ?? ['ingest', 'read'],
            'expires_at' => $data['expires_at'] ?? null,
            'allowed_ips' => $data['allowed_ips'] ?? null,
            'active' => true,
        ]);

        $this->audit($request, 'CREATE', ApiKey::class, $key->id, [
            'name' => $key->name, 'scopes' => $key->scopes,
            'expires_at' => optional($key->expires_at)->toDateString(),
            'allowed_ips' => $key->allowed_ips,
        ]);

        return response()->json(['data' => [
            'id' => $key->id, 'name' => $key->name, 'prefix' => $prefix,
            'expires_at' => optional($key->expires_at)->toDateString(),
            'allowed_ips' => $key->allowed_ips,
        ], 'secret' => $secret], 201);
    }

    public function revokeKey(Request $request, ApiKey $apiKey): JsonResponse
    {
        abort_unless($apiKey->company_id === $request->user()->company_id, 404);
        $apiKey->update(['active' => false]);
        $this->audit($request, 'REVOKE', ApiKey::class, $apiKey->id, ['name' => $apiKey->name]);

        return response()->json(['ok' => true]);
    }

    // ---------- Outbound targets ----------

    public function targets(Request $request): JsonResponse
    {
        $rows = IntegrationTarget::where('company_id', $request->user()->company_id)->latest('id')->get()
            ->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'url' => $t->url,
                'events' => $t->events, 'active' => $t->active,
                'has_secret' => (bool) $t->secret,
                'last_pushed_at' => optional($t->last_pushed_at)->toDateTimeString(),
                'last_status' => $t->last_status,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function saveTarget(Request $request, ?IntegrationTarget $target = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'secret' => ['nullable', 'string', 'max:200'],
            'events' => ['nullable', 'array'],
            'active' => ['boolean'],
        ]);

        if ($target && $target->exists) {
            abort_unless($target->company_id === $request->user()->company_id, 404);
            // Blank secret on edit keeps the existing one.
            if (empty($data['secret'])) unset($data['secret']);
            $target->update($data);
        } else {
            $target = IntegrationTarget::create($data + [
                'company_id' => $request->user()->company_id,
                'events' => $data['events'] ?? ['attendance.daily'],
                'secret' => $data['secret'] ?? Str::random(32),
            ]);
        }

        $this->audit($request, $target->wasRecentlyCreated ? 'CREATE' : 'UPDATE', IntegrationTarget::class, $target->id, ['name' => $target->name, 'url' => $target->url]);

        return response()->json(['data' => $target->only(['id', 'name', 'url', 'events', 'active'])], 201);
    }

    public function deleteTarget(Request $request, IntegrationTarget $target): JsonResponse
    {
        abort_unless($target->company_id === $request->user()->company_id, 404);
        $target->delete();
        $this->audit($request, 'DELETE', IntegrationTarget::class, $target->id);

        return response()->json(['ok' => true]);
    }

    /** Push a date's attendance to one target now (Test / manual run). */
    public function pushTarget(Request $request, IntegrationTarget $target, OutboundPusher $pusher): JsonResponse
    {
        abort_unless($target->company_id === $request->user()->company_id, 404);
        $date = $request->input('date', now()->subDay()->toDateString());
        $payload = $pusher->attendancePayload($target->company_id, $date);
        $res = $pusher->pushTo($target, $payload);
        $this->audit($request, 'PUSH', IntegrationTarget::class, $target->id, ['date' => $date, 'result' => $res['status']]);

        return response()->json(['ok' => $res['ok'], 'status' => $res['status'], 'records' => $payload['count']]);
    }
}
