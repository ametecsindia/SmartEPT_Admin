<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeChatMessage;
use App\Support\ResolvesAgentContext;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 28-Sep-2026: admin ↔ employee chat. The admin writes from the employee drawer; the agent
 * learns of new messages on its 3-second LiveView poll (chat_unread) and pops a chat window.
 * Each side's GET marks the OTHER side's messages as read.
 */
class ChatController extends Controller
{
    use ScopesVisibleEmployees;
    use ResolvesAgentContext;

    private const PAGE = 200;

    /** 30-Sep-2026: sender name + role for the "from" line on the agent. */
    private const SENDER = ['senderUser:id,name,role_id', 'senderUser.role:id,name,slug'];

    // ---- admin console ----

    public function adminIndex(Request $request, Employee $employee): JsonResponse
    {
        $this->assertCanChat($request, $employee);
        return response()->json($this->thread($employee, 'EMPLOYEE', (int) $request->query('after_id', 0)));
    }

    public function adminSend(Request $request, Employee $employee): JsonResponse
    {
        $this->assertCanChat($request, $employee);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $m = EmployeeChatMessage::create([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id,
            'sender' => 'ADMIN', 'sender_user_id' => $request->user()->id, 'body' => trim($data['body']),
        ]);

        return response()->json(['data' => $m->load(self::SENDER)->toChat()], 201);
    }

    /**
     * 29-Sep-2026 (Ejaz): a chat lives only while it is open. Closing it on EITHER side
     * (admin drawer / full-screen chat, or the agent's popup) deletes the thread.
     */
    public function adminClear(Request $request, Employee $employee): JsonResponse
    {
        $this->assertCanChat($request, $employee);
        EmployeeChatMessage::clearFor($employee->id);
        return response()->json(['ok' => true]);
    }

    /**
     * 30-Sep-2026 (Ejaz): employee replies the admin has not opened yet, per employee — the
     * console polls this to toast/notify and to badge the LiveView chat icons. Only employees
     * in the caller's scope; reading the thread (adminIndex) clears the count.
     */
    public function adminUnread(Request $request): JsonResponse
    {
        $visible = $this->visibleEmployeeIds($request->user());
        $rows = EmployeeChatMessage::query() // BelongsToCompany bounds it to the tenant
            ->where('sender', 'EMPLOYEE')->whereNull('read_at')
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible))
            ->selectRaw('employee_id, COUNT(*) AS n, MAX(id) AS last_id')->groupBy('employee_id')->get();
        if ($rows->isEmpty()) return response()->json(['data' => []]);

        $names = Employee::whereIn('id', $rows->pluck('employee_id'))->get()->mapWithKeys(fn ($e) => [$e->id => $e->fullName()]);
        $bodies = EmployeeChatMessage::query()->whereIn('id', $rows->pluck('last_id'))->pluck('body', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => [
            'employee_id' => (int) $r->employee_id,
            'name'        => $names[$r->employee_id] ?? ('Employee #' . $r->employee_id),
            'count'       => (int) $r->n,
            'last_id'     => (int) $r->last_id,
            'body'        => mb_strimwidth((string) ($bodies[$r->last_id] ?? ''), 0, 90, '…'),
        ])->values()]);
    }

    // ---- agent ----

    public function agentClear(Request $request): JsonResponse
    {
        EmployeeChatMessage::clearFor($this->agentEmployee($request)->id);
        return response()->json(['ok' => true]);
    }

    public function agentIndex(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        return response()->json($this->thread($employee, 'ADMIN', (int) $request->query('after_id', 0)));
    }

    public function agentSend(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $m = EmployeeChatMessage::withoutGlobalScopes()->create([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id,
            'sender' => 'EMPLOYEE', 'body' => trim($data['body']),
        ]);

        return response()->json(['data' => $m->toChat()], 201);
    }

    /**
     * Unread admin→employee messages; piggybacked on the agent's fast poll.
     * 30-Sep-2026: the poll reaching the employee's PC is what "delivered" (✓✓ grey) means.
     */
    public static function unreadForEmployee(int $employeeId): int
    {
        EmployeeChatMessage::withoutGlobalScopes()
            ->where('employee_id', $employeeId)->where('sender', 'ADMIN')->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        return EmployeeChatMessage::withoutGlobalScopes()
            ->where('employee_id', $employeeId)->where('sender', 'ADMIN')->whereNull('read_at')->count();
    }

    // ---- shared ----

    /**
     * Messages after $afterId (or the latest page); marks the other side's messages read.
     * 30-Sep-2026: also returns `receipts` for the caller's OWN messages — the highest id
     * delivered and read. Reads/deliveries mark everything up to "now" at once, so every own
     * message with id <= read_upto is read; the client re-ticks bubbles already on screen.
     */
    private function thread(Employee $employee, string $markReadFrom, int $afterId): array
    {
        $q = EmployeeChatMessage::withoutGlobalScopes()->with(self::SENDER)
            ->where('employee_id', $employee->id);

        $rows = $afterId > 0
            ? $q->where('id', '>', $afterId)->orderBy('id')->limit(self::PAGE)->get()
            : $q->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();

        $base = fn () => EmployeeChatMessage::withoutGlobalScopes()->where('employee_id', $employee->id);
        $base()->where('sender', $markReadFrom)->whereNull('delivered_at')->update(['delivered_at' => now()]);
        $base()->where('sender', $markReadFrom)->whereNull('read_at')->update(['read_at' => now()]);

        $own = $markReadFrom === 'ADMIN' ? 'EMPLOYEE' : 'ADMIN';
        $readUpto = (int) $base()->where('sender', $own)->whereNotNull('read_at')->max('id');
        $delivUpto = (int) $base()->where('sender', $own)->whereNotNull('delivered_at')->max('id');

        return [
            'data'     => $rows->map->toChat()->all(),
            'receipts' => ['read_upto' => $readUpto, 'delivered_upto' => max($readUpto, $delivUpto)],
        ];
    }

    private function assertCanChat(Request $request, Employee $employee): void
    {
        // Employee self-service logins never chat through the console — that is the agent's side.
        $slug = $request->user()->role?->base_slug ?: $request->user()->roleSlug();
        abort_if($slug === 'EMPLOYEE', 403, 'Chat is for administrators and managers.');
        $this->assertEmployeeVisible($request, $employee->id);
    }
}
