/**
 * Local calendar dates for tests.
 *
 * The calendar renders local days — `.fc-timegrid-col[data-date]` is a local date — so building
 * one with `new Date(y, m, d).toISOString()` is wrong everywhere east of Greenwich: local
 * midnight is the previous day in UTC, so the selector points at the wrong column and the test
 * fails only on hosts with a positive offset, or only in summer. Several specs did exactly that.
 */

/** Today's local date as `YYYY-MM-DD`, or another day relative to it. */
export function localDate(offsetDays = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + offsetDays);
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
}

/** The local date an instant falls on — what the calendar shows it under. */
export function localDateOf(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
}

/** The local wall-clock time an instant falls on as `HH:MM` — what a time picker would show. */
export function localTimeOf(date: Date): string {
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
}

/**
 * A local date and `HH:MM` as the UTC instant the API stores, in the strict `Y-m-d\TH:i:s\Z`
 * form the endpoints require. Use this whenever a test types a time into the UI and then asserts
 * on the stored timestamp — the two differ by the host's offset, and hard coding the UTC string
 * makes the test pass only at UTC+0.
 */
export function localTimeToUtc(date: string, time: string): string {
    return new Date(`${date}T${time}:00`).toISOString().replace(/\.\d{3}Z$/, 'Z');
}
