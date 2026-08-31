<?php

namespace Tests\Unit;

use App\Services\Biometric\PunchDirectionResolver as R;
use PHPUnit\Framework\TestCase;

/**
 * The single-reader IN/OUT rule (Ejaz, 28-Aug-2026).
 *
 * PunchDirectionResolver::sequence() is the whole rule with no database in it, so this
 * runs as a plain unit test: give it one day of punches in time order, get back the
 * indexes whose direction has to change.
 */
class PunchDirectionSequenceTest extends TestCase
{
    /** Apply the rule and return the day's final directions. */
    private function finalDirections(array $day): array
    {
        $changes = R::sequence($day);

        $out = [];
        foreach ($day as $i => $punch) {
            $out[] = $changes[$i] ?? $punch['punch_type'];
        }

        return $out;
    }

    private function punch(string $mode, string $type): array
    {
        return ['mode' => $mode, 'punch_type' => $type];
    }

    public function test_one_reader_used_both_ways_alternates_in_out(): void
    {
        // Ejaz's example: 09:05, 13:10, 14:00, 18:15 on ONE reader, all arriving as IN.
        $day = array_fill(0, 4, $this->punch('IN_OUT', 'IN'));

        $this->assertSame(['IN', 'OUT', 'IN', 'OUT'], $this->finalDirections($day));
    }

    public function test_an_odd_number_of_punches_still_starts_with_in(): void
    {
        $day = array_fill(0, 5, $this->punch('IN_OUT', 'OUT'));

        $this->assertSame(['IN', 'OUT', 'IN', 'OUT', 'IN'], $this->finalDirections($day));
    }

    public function test_a_correct_day_produces_no_changes(): void
    {
        $day = [
            $this->punch('IN_OUT', 'IN'),
            $this->punch('IN_OUT', 'OUT'),
            $this->punch('IN_OUT', 'IN'),
            $this->punch('IN_OUT', 'OUT'),
        ];

        $this->assertSame([], R::sequence($day));
    }

    public function test_dedicated_entry_and_exit_readers_are_never_rewritten(): void
    {
        $day = [
            $this->punch('IN_ONLY', 'IN'),
            $this->punch('OUT_ONLY', 'OUT'),
        ];

        $this->assertSame([], R::sequence($day));
    }

    public function test_a_fixed_reader_sets_the_parity_for_the_alternating_one(): void
    {
        // Turnstile IN → floor reader (both ways) twice → turnstile OUT.
        $day = [
            $this->punch('IN_ONLY', 'IN'),
            $this->punch('IN_OUT', 'IN'),
            $this->punch('IN_OUT', 'IN'),
            $this->punch('OUT_ONLY', 'OUT'),
        ];

        $this->assertSame(['IN', 'OUT', 'IN', 'OUT'], $this->finalDirections($day));
    }

    public function test_a_legacy_auto_device_is_left_alone_but_still_sets_parity(): void
    {
        $day = [
            $this->punch('AUTO', 'IN'),
            $this->punch('IN_OUT', 'IN'),
        ];

        $this->assertSame(['IN', 'OUT'], $this->finalDirections($day));
    }

    public function test_break_punches_are_neither_rewritten_nor_counted(): void
    {
        $day = [
            $this->punch('IN_OUT', 'IN'),
            $this->punch('IN_OUT', 'BREAK_OUT'),
            $this->punch('IN_OUT', 'IN'),
        ];

        $this->assertSame(['IN', 'BREAK_OUT', 'OUT'], $this->finalDirections($day));
    }

    public function test_running_the_rule_twice_changes_nothing_the_second_time(): void
    {
        // Every sync re-derives the whole day, so the rule must be idempotent.
        $day = array_fill(0, 3, $this->punch('IN_OUT', 'IN'));

        $settled = [];
        foreach ($this->finalDirections($day) as $type) {
            $settled[] = $this->punch('IN_OUT', $type);
        }

        $this->assertSame([], R::sequence($settled));
    }

    public function test_an_empty_day_is_handled(): void
    {
        $this->assertSame([], R::sequence([]));
    }
}
