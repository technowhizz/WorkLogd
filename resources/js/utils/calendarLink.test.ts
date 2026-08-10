import { describe, expect, it } from 'vitest';
import { calendarDateForBucket, calendarHref } from './calendarLink';

describe('calendarDateForBucket', () => {
    it('passes a day bucket through unchanged', () => {
        expect(calendarDateForBucket('2026-08-12', 'day')).toBe('2026-08-12');
    });

    /*
     * A week key is date_bin'd onto the organization's week start by
     * TimeEntryAggregationService, so it is already the first day of its own week — the
     * calendar opens the week containing whatever date it is given, so no adjustment is
     * needed and none must be added.
     */
    it('passes a week bucket through unchanged', () => {
        expect(calendarDateForBucket('2026-08-10', 'week')).toBe('2026-08-10');
    });

    it('opens a month bucket on the 1st', () => {
        expect(calendarDateForBucket('2026-08', 'month')).toBe('2026-08-01');
    });

    it('opens a year bucket on 1 January', () => {
        expect(calendarDateForBucket('2026', 'year')).toBe('2026-01-01');
    });

    // Day and week keys share a shape, so the type is what distinguishes them — a month key
    // arriving under the day type is a mismatch, not something to coerce.
    it('rejects a key whose shape does not match its grouping', () => {
        expect(calendarDateForBucket('2026-08', 'day')).toBeNull();
        expect(calendarDateForBucket('2026-08-12', 'month')).toBeNull();
        expect(calendarDateForBucket('2026-08', 'year')).toBeNull();
    });

    /*
     * The aggregation endpoint can group by project, client, tag and more. Those keys are
     * UUIDs or free text, and navigating on one would produce `?date=<uuid>`, so anything
     * that is not a time interval has to fall through to null.
     */
    it('rejects a non-time grouping', () => {
        expect(calendarDateForBucket('9e27f54d-5dfb-4dde-99d7-834518236c92', 'project')).toBeNull();
        expect(calendarDateForBucket('2026-08-12', null)).toBeNull();
        expect(calendarDateForBucket('2026-08-12', undefined)).toBeNull();
    });

    it('rejects a missing or malformed key', () => {
        expect(calendarDateForBucket(null, 'day')).toBeNull();
        expect(calendarDateForBucket(undefined, 'day')).toBeNull();
        expect(calendarDateForBucket('', 'day')).toBeNull();
        expect(calendarDateForBucket('not a date', 'day')).toBeNull();
        expect(calendarDateForBucket('2026-8-1', 'day')).toBeNull();
    });
});

describe('calendarHref', () => {
    it('builds the calendar deep link', () => {
        expect(calendarHref('2026-08-10')).toBe('/calendar?date=2026-08-10');
    });
});
