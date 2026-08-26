<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * "Now", in the same frame as the values the agent stores.
 *
 * 26-Aug-2026 (Ejaz). The agent writes LOCAL wall-clock times — a sign-in at 12:10 IST is
 * stored as 12:10 — and `shifts.end_time` is likewise a local clock face ("18:00" means 18:00
 * where the employee sits). But `now()` returns the APP timezone, which `.env` ships as UTC.
 * Comparing the two is a straight 5h30m error for an India tenant, and it silently breaks
 * anything that asks "has this instant passed yet?":
 *
 *   - post-shift auto sign-out reported "not due yet" for a shift that ended two minutes ago,
 *     and only became due 5h30m late;
 *   - the silent-device sweep never fired at all, because every stored heartbeat looks like it
 *     is in the future compared with a UTC "now";
 *   - the nightly stale-session close skipped sessions whose shift had genuinely ended.
 *
 * ResolvesBusinessDay does the same job for HTTP requests (branch > company > app default).
 * There is no request in a console command, so this resolves company > app default and caches
 * per company — the scheduler runs these every five minutes.
 */
trait ResolvesLocalNow
{
    /** company_id => IANA timezone */
    private array $tzCache = [];

    /**
     * Wall-clock "now" for a company, returned WITHOUT a timezone attached so it compares
     * directly against the naive values in the database. Never use it to compute a duration
     * across a DST boundary — it is a clock face, not an instant.
     */
    protected function localNow(?int $companyId): Carbon
    {
        return Carbon::parse(Carbon::now($this->companyTz($companyId))->format('Y-m-d H:i:s'));
    }

    protected function companyTz(?int $companyId): string
    {
        if ($companyId === null) {
            return config('app.timezone', 'UTC');
        }

        return $this->tzCache[$companyId] ??= (Company::withoutGlobalScopes()->find($companyId)?->timezone
            ?: config('app.timezone', 'UTC'));
    }
}
