<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * EPT-20: timezone-correct day boundaries.
 *
 * Activity timestamps (started_at, captured_at, start_at, ...) are stored in UTC.
 * "Today" and per-date report windows must be evaluated against the tenant's LOCAL
 * calendar day, not UTC — a company in Asia/Kolkata rolls over at 00:00 IST, which
 * is 18:30 UTC the previous day. Comparing whereDate() against a UTC date therefore
 * mis-buckets the last/first ~5.5h of every day.
 *
 * Timezone resolves: branch override (when the request is filtered to one branch and
 * that branch sets its own timezone) > company default > app default.
 */
trait ResolvesBusinessDay
{
    /** Effective IANA timezone for this request. */
    protected function bizTz(Request $request): string
    {
        $tz = $request->user()?->company?->timezone ?: config('app.timezone', 'UTC');

        $branchId = $request->query('branch_id');
        if ($branchId) {
            $branch = Branch::find((int) $branchId);
            if ($branch && $branch->timezone) {
                $tz = $branch->timezone;
            }
        }

        return $tz;
    }

    /** Today's date (Y-m-d) in the given timezone. */
    protected function bizToday(string $tz): string
    {
        return Carbon::now($tz)->toDateString();
    }

    /**
     * UTC [start, end] bounds of a local calendar day, for range queries over
     * UTC-stored timestamps:  ->whereBetween($col, $this->dayUtcBounds($date, $tz)).
     * end is 23:59:59.999999 local, so it is inclusive-equivalent to whereDate()
     * for second-precision columns while staying timezone-correct.
     */
    protected function dayUtcBounds(string $date, string $tz): array
    {
        $d = Carbon::parse($date, $tz);

        return [$d->clone()->startOfDay()->utc(), $d->clone()->endOfDay()->utc()];
    }
}
