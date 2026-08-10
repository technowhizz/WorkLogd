/**
 * Deep links from a reporting time bucket into the calendar.
 *
 * The calendar reads `?date=` once during setup (`Pages/Calendar.vue`) and opens the week
 * containing that day, so a bucket only has to resolve to one representative local date.
 */

const DAY = /^\d{4}-\d{2}-\d{2}$/;
const MONTH = /^\d{4}-\d{2}$/;
const YEAR = /^\d{4}$/;

/**
 * The calendar day a reporting bucket should open on, or null when the key cannot be trusted.
 *
 * The keys come from `TimeEntryAggregationService::getGroupByQuery()` and are already in the
 * user's timezone and week start — a week bucket is `date_bin`'d onto the organization's week
 * boundary, so it is the week's own first day and needs no client-side week maths. Day and week
 * keys are therefore indistinguishable by shape, which is why `groupedType` decides rather than
 * the key alone.
 *
 * A month opens on the 1st, so you land on the week the month starts in. Year is not reachable
 * from the current UI — `getOptimalGroupingOption()` only ever asks for day, week or month — but
 * the API accepts it, so it is mapped rather than left to fall through to null.
 *
 * Returning null instead of throwing keeps callers to one line: an unrecognised bucket simply
 * does not navigate, rather than sending the calendar a `?date=undefined` it would silently
 * ignore.
 */
export function calendarDateForBucket(
    key: string | null | undefined,
    groupedType: string | null | undefined
): string | null {
    if (typeof key !== 'string') return null;

    switch (groupedType) {
        case 'day':
        case 'week':
            return DAY.test(key) ? key : null;
        case 'month':
            return MONTH.test(key) ? `${key}-01` : null;
        case 'year':
            return YEAR.test(key) ? `${key}-01-01` : null;
        default:
            return null;
    }
}

/** The calendar deep link for a local `YYYY-MM-DD` day. */
export function calendarHref(date: string): string {
    return `/calendar?date=${date}`;
}
