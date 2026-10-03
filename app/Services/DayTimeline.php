<?php

namespace App\Services;

use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\EmployeeActivityEvent;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeLoginSession;
use Illuminate\Support\Carbon;

/**
 * 29-Sep-2026 (Ejaz): one employee's day, event by event — door INs/OUTs, PC sign-ins, every
 * Idle spell and the moment Active resumed, every Away (door OUT with no Break) with the
 * moment they were back at the desk, and declared breaks. Feeds the "+" detail row of the
 * Productivity report, and gives the report its two Away corrections (see adjustments()).
 *
 * Definitions (all times are the stored local times):
 *  - Away           = punched out without a break and punched back in (→ back at the desk: first
 *                     keyboard/mouse after the door IN — the walk back in is part of the Away, Ejaz
 *                     29-Sep-2026), OR signed out of the app and signed back in (sign-out → next
 *                     sign-in; the only Away there is when no door is configured).
 *  - Idle           = also any stretch while SIGNED IN that the agent sent nothing for (PC off,
 *                     agent closed) — signed in + no activity is Idle (Ejaz, 29-Sep-2026).
 *  - Gate → PC      = the day's first walk-in: first door IN → first sign-in, as before.
 *  - Idle           = IDLE stretches, minus any part that falls inside an Away or a Gate → PC
 *                     window — that time is already counted there and must not be counted twice.
 */
class DayTimeline
{
    /** Gap (s) under which two IDLE stretches are one spell — the agent cuts stretches every 60s. */
    private const JOIN = 10;

    public static function build(Employee $emp, string $date): array
    {
        [$from, $to] = [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()];
        $t = fn ($c) => $c ? Carbon::parse($c)->format('H:i:s') : null;

        // Door punches, paired IN n / OUT n.
        $door = [];
        foreach (BiometricLog::withoutGlobalScopes()->where('employee_id', $emp->id)
            ->whereBetween('punched_at', [$from, $to])->orderBy('punched_at')->get(['punch_type', 'punched_at']) as $p) {
            $isIn = in_array($p->punch_type, ['IN', 'BREAK_IN'], true);
            if ($isIn || ! $door || end($door)['out'] !== null) {
                $door[] = ['n' => count($door) + 1, 'in' => $isIn ? $t($p->punched_at) : null, 'out' => $isIn ? null : $t($p->punched_at)];
            } else {
                $door[count($door) - 1]['out'] = $t($p->punched_at);
            }
        }

        $sessRows = EmployeeLoginSession::withoutGlobalScopes()->where('employee_id', $emp->id)
            ->whereBetween('login_at', [$from, $to])->orderBy('login_at')
            ->get(['login_at', 'logout_at', 'logout_reason'])->values();
        $sessions = $sessRows->map(fn ($s, $i) => ['n' => $i + 1, 'sign_in' => $t($s->login_at), 'sign_out' => $t($s->logout_at),
                'reason' => $s->logout_reason])->all();

        $events = EmployeeActivityEvent::withoutGlobalScopes()->where('employee_id', $emp->id)
            ->whereBetween('started_at', [$from, $to])->orderBy('started_at')
            ->get(['event_type', 'started_at', 'ended_at', 'duration_seconds']);
        $end = fn ($e) => $e->ended_at ? Carbon::parse($e->ended_at) : Carbon::parse($e->started_at)->addSeconds((int) $e->duration_seconds);

        [$away, $windows] = self::awayWindows($emp, $from, $to, $events);

        // Signed out of the app and signed back in = Away too (skipped where a door Away already covers it).
        foreach ($sessRows as $i => $s) {
            $next = $sessRows[$i + 1] ?? null;
            if (! $s->logout_at || ! $next) continue;
            [$o, $b] = [Carbon::parse($s->logout_at), Carbon::parse($next->login_at)];
            if ($b->getTimestamp() - $o->getTimestamp() < 60 || self::overlap($o, $b, $windows) > 0) continue; // < 1 min: a re-sign-in, not an Away
            $windows[] = [$o, $b];
            $away[] = ['n' => 0, 'how' => 'Signed out', 'out' => $o->format('H:i:s'), 'in' => $b->format('H:i:s'), 'back_at_desk' => $b->format('H:i:s'),
                'door_seconds' => (int) $b->diffInSeconds($o, true), 'gate_to_pc_seconds' => 0, 'away_seconds' => (int) $b->diffInSeconds($o, true)];
        }
        usort($away, fn ($a, $b) => strcmp($a['out'], $b['out']));
        foreach ($away as $i => &$a) { $a['n'] = $i + 1; }
        unset($a);

        // Idle spells (stretches joined), with the double-counted part inside Away / Gate→PC.
        $spells = [];
        foreach ($events->where('event_type', 'IDLE') as $e) {
            $s = Carbon::parse($e->started_at); $x = $end($e);
            $ov = self::overlap($s, $x, $windows);
            $last = count($spells) - 1;
            if ($last >= 0 && $s->lessThanOrEqualTo($spells[$last]['_to']->copy()->addSeconds(self::JOIN))) {
                $spells[$last]['_to'] = $x->greaterThan($spells[$last]['_to']) ? $x : $spells[$last]['_to'];
                $spells[$last]['seconds'] += (int) $e->duration_seconds;
                $spells[$last]['in_away_seconds'] += $ov;
            } else {
                $spells[] = ['_from' => $s, '_to' => $x, 'seconds' => (int) $e->duration_seconds, 'in_away_seconds' => $ov];
            }
        }
        // Signed in but the agent sent nothing (PC off / agent closed) = Idle.
        $covered = $windows;
        foreach ($events as $e) $covered[] = [Carbon::parse($e->started_at), $end($e)];
        foreach (EmployeeBreakLog::withoutGlobalScopes()->where('employee_id', $emp->id)->whereBetween('start_at', [$from, $to])
            ->get(['start_at', 'end_at', 'duration_seconds']) as $b) {
            $covered[] = [Carbon::parse($b->start_at), $b->end_at ? Carbon::parse($b->end_at) : now()];
        }
        $lastSeen = collect($covered)->max(fn ($w) => $w[1]);
        foreach ($sessRows as $s) {
            $sEnd = $s->logout_at ? Carbon::parse($s->logout_at)
                : ($from->isToday() ? now() : ($lastSeen ?? Carbon::parse($s->login_at)));   // open session: today runs to now
            foreach (self::holes($covered, Carbon::parse($s->login_at), $sEnd->lessThan($to) ? $sEnd : $to) as [$a, $b]) {
                if ($b->diffInSeconds($a, true) < 60) continue;   // ponytail: < 1 min is sync rounding, not a spell
                $spells[] = ['_from' => $a, '_to' => $b, 'seconds' => (int) $b->diffInSeconds($a, true), 'in_away_seconds' => 0, 'no_data' => true];
            }
        }
        usort($spells, fn ($a, $b) => $a['_from'] <=> $b['_from']);
        $idle = [];
        foreach ($spells as $i => $sp) {
            $back = $events->first(fn ($e) => $e->event_type === 'ACTIVE'
                && Carbon::parse($e->started_at)->betweenIncluded($sp['_to']->copy()->subSeconds(self::JOIN), $sp['_to']->copy()->addSeconds(self::JOIN)));
            $idle[] = ['n' => $i + 1, 'from' => $sp['_from']->format('H:i:s'), 'to' => $sp['_to']->format('H:i:s'),
                'returned_to_active' => $back ? Carbon::parse($back->started_at)->format('H:i:s') : null,
                'no_data' => ! empty($sp['no_data']),
                'seconds' => $sp['seconds'], 'in_away_seconds' => $sp['in_away_seconds'],
                'counted_seconds' => max(0, $sp['seconds'] - $sp['in_away_seconds'])];
        }

        $breaks = EmployeeBreakLog::withoutGlobalScopes()->where('employee_id', $emp->id)
            ->whereBetween('start_at', [$from, $to])
            ->where(fn ($q) => $q->where('source', '!=', 'BIOMETRIC')->orWhereNotNull('device_uuid'))
            ->orderBy('start_at')->get(['break_type', 'start_at', 'end_at', 'duration_seconds'])->values()
            ->map(fn ($b, $i) => ['n' => $i + 1, 'type' => $b->break_type, 'from' => $t($b->start_at), 'to' => $t($b->end_at),
                'seconds' => $b->end_at ? (int) $b->duration_seconds : max(0, (int) now()->diffInSeconds($b->start_at, true))])->all();

        $firstIn = collect($door)->pluck('in')->filter()->first();
        $firstSign = $sessions[0]['sign_in'] ?? null;
        $firstG2p = ($firstIn && $firstSign && $firstIn < $firstSign)
            ? (int) Carbon::parse($date . ' ' . $firstSign)->diffInSeconds(Carbon::parse($date . ' ' . $firstIn), true) : 0;

        $sum = fn ($rows, $k) => array_sum(array_column($rows, $k));

        return [
            'date' => $date,
            'employee' => ['id' => $emp->id, 'code' => $emp->employee_code, 'name' => trim($emp->first_name . ' ' . $emp->last_name)],
            'door' => $door,
            'sessions' => $sessions,
            'idle' => $idle,
            'away' => $away,
            'breaks' => $breaks,
            'totals' => [
                'door_in_count' => count(array_filter(array_column($door, 'in'))),
                'door_out_count' => count(array_filter(array_column($door, 'out'))),
                'sign_in_count' => count($sessions),
                'idle_count' => count($idle),
                'idle_seconds' => $sum($idle, 'counted_seconds'),
                'idle_in_away_seconds' => $sum($idle, 'in_away_seconds'),
                'away_count' => count($away),
                'away_seconds' => $sum($away, 'away_seconds'),
                'first_gate_to_pc_seconds' => $firstG2p,
                'return_gate_to_pc_seconds' => $sum($away, 'gate_to_pc_seconds'),
                'gate_to_pc_seconds' => $firstG2p,
                'break_count' => count($breaks),
                'break_seconds' => $sum($breaks, 'seconds'),
            ],
        ];
    }

    /**
     * What the Productivity report row needs from the Away windows alone (cheap: only called for
     * days that HAVE a door punch-out without a Break):
     *  - return_gate_to_pc: door IN → back at the desk, summed over every return;
     *  - idle_overlap: IDLE seconds inside those Away / Gate→PC windows (already counted there).
     */
    public static function adjustments(Employee $emp, string $date): array
    {
        [$from, $to] = [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()];
        [$away, $windows] = self::awayWindows($emp, $from, $to, null);
        if (! $windows) {
            return ['return_gate_to_pc' => 0, 'idle_overlap' => 0, 'walks' => []];
        }
        $ov = 0;
        foreach (EmployeeActivityEvent::withoutGlobalScopes()->where('employee_id', $emp->id)->where('event_type', 'IDLE')
            ->where('started_at', '<', max(array_column($windows, 1)))
            ->where('started_at', '>=', min(array_column($windows, 0))->copy()->subDay())
            ->get(['started_at', 'ended_at', 'duration_seconds']) as $e) {
            $s = Carbon::parse($e->started_at);
            $ov += self::overlap($s, $e->ended_at ? Carbon::parse($e->ended_at) : $s->copy()->addSeconds((int) $e->duration_seconds), $windows);
        }

        // The walk-back windows (door IN → back at the desk), so the report never counts them twice.
        $walks = array_values(array_filter($windows, fn ($w) => in_array($w[0]->format('H:i:s'), array_column($away, 'in'), true)));

        return ['return_gate_to_pc' => array_sum(array_column($away, 'gate_to_pc_seconds')), 'idle_overlap' => $ov, 'walks' => $walks];
    }

    /** Away rows + the [start, end] windows (OUT→IN and IN→back at desk) they cover. */
    private static function awayWindows(Employee $emp, Carbon $from, Carbon $to, $events): array
    {
        $away = []; $windows = [];
        $rows = EmployeeBreakLog::withoutGlobalScopes()->unannounced()->where('employee_id', $emp->id)
            ->whereBetween('start_at', [$from, $to])->orderBy('start_at')->get(['start_at', 'end_at', 'duration_seconds']);
        foreach ($rows->values() as $i => $b) {
            $out = Carbon::parse($b->start_at);
            $in = $b->end_at ? Carbon::parse($b->end_at) : null;
            $back = null;
            if ($in) {
                // First keyboard/mouse activity after walking back in (within 4h, same day).
                $cand = $events
                    ? $events->first(fn ($e) => $e->event_type === 'ACTIVE' && Carbon::parse($e->started_at)->greaterThanOrEqualTo($in))
                    : EmployeeActivityEvent::withoutGlobalScopes()->where('employee_id', $emp->id)->where('event_type', 'ACTIVE')
                        ->where('started_at', '>=', $in)->orderBy('started_at')->first(['started_at']);
                $c = $cand ? Carbon::parse($cand->started_at) : null;
                $back = ($c && $c->lessThanOrEqualTo($in->copy()->addHours(4)) && $c->lessThanOrEqualTo($to)) ? $c : null;
            }
            $windows[] = [$out, $in ?? now()];
            if ($back) $windows[] = [$in, $back];
            $away[] = ['n' => $i + 1, 'how' => 'Door', 'out' => $out->format('H:i:s'), 'in' => $in?->format('H:i:s'),
                'back_at_desk' => $back?->format('H:i:s'),
                'door_seconds' => $in ? (int) $b->duration_seconds : max(0, (int) now()->diffInSeconds($out, true)),
                'gate_to_pc_seconds' => $back ? (int) $back->diffInSeconds($in, true) : 0];
            $away[$i]['away_seconds'] = $away[$i]['door_seconds'] + $away[$i]['gate_to_pc_seconds'];
        }

        return [$away, $windows];
    }

    /** The parts of [a, b] that no window in $spans covers, as [from, to] pairs. */
    public static function holes(array $spans, Carbon $a, Carbon $b): array
    {
        usort($spans, fn ($x, $y) => $x[0] <=> $y[0]);
        $holes = []; $cur = $a->copy();
        foreach ($spans as [$s, $e]) {
            if ($cur->greaterThanOrEqualTo($b)) break;
            if ($s->greaterThan($cur)) $holes[] = [$cur->copy(), $s->lessThan($b) ? $s->copy() : $b->copy()];
            if ($e->greaterThan($cur)) $cur = $e->copy();
        }
        if ($cur->lessThan($b)) $holes[] = [$cur->copy(), $b->copy()];

        return $holes;
    }

    /** Seconds of [a, b] covered by the union of $windows. */
    private static function overlap(Carbon $a, Carbon $b, array $windows): int
    {
        $parts = [];
        foreach ($windows as [$s, $e]) {
            $s = $s->greaterThan($a) ? $s : $a;
            $e = $e->lessThan($b) ? $e : $b;
            if ($e->greaterThan($s)) $parts[] = [$s->getTimestamp(), $e->getTimestamp()];
        }
        sort($parts);
        $sum = 0; $cur = null;
        foreach ($parts as [$s, $e]) {
            if ($cur && $s <= $cur[1]) { $cur[1] = max($cur[1], $e); continue; }
            if ($cur) $sum += $cur[1] - $cur[0];
            $cur = [$s, $e];
        }

        return $sum + ($cur ? $cur[1] - $cur[0] : 0);
    }
}
