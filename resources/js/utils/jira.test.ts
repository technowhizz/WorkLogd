import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    describeInvalidRange,
    describeSkipReason,
    detectIssueKey,
    issueBrowseUrl,
    missingReferenceBadges,
    parseProjectKeys,
    toExternalSyncBadges,
    type MissingReferenceCandidate,
} from './jira';

const mocks = vi.hoisted(() => ({
    sharedUser: {} as Record<string, unknown>,
    updateUser: vi.fn(),
    addNotification: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        get props() {
            return { auth: { user: mocks.sharedUser } };
        },
    }),
}));

vi.mock('@/packages/api/src', () => ({
    api: {
        updateUser: (...args: unknown[]) => mocks.updateUser(...args),
    },
}));

vi.mock('@/utils/useUser', () => ({
    getCurrentUserId: () => 'user-1',
}));

vi.mock('@/utils/notification', () => ({
    useNotificationsStore: () => ({ addNotification: mocks.addNotification }),
}));

const LEGACY_KEY = 'solidtime:jira-missing-ticket-hints';

/** The module keeps per-tab state, so every case needs its own copy of it. */
async function loadJiraModule() {
    vi.resetModules();
    return import('./jira');
}

describe('showMissingTicketHintsSetting', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.localStorage.clear();
        mocks.sharedUser = { id: 'user-1', show_missing_ticket_hints: false };
        mocks.updateUser.mockResolvedValue({ data: {} });
    });

    it('reads the setting off the account, so it follows you to another device', async () => {
        mocks.sharedUser = { id: 'user-1', show_missing_ticket_hints: true };

        const { showMissingTicketHintsSetting } = await loadJiraModule();

        expect(showMissingTicketHintsSetting.value).toBe(true);
    });

    it('stays off for a session whose shared props predate the setting', async () => {
        mocks.sharedUser = { id: 'user-1' };

        const { showMissingTicketHintsSetting } = await loadJiraModule();

        expect(showMissingTicketHintsSetting.value).toBe(false);
    });

    it('saves to the account when it is switched on', async () => {
        const { showMissingTicketHintsSetting } = await loadJiraModule();

        showMissingTicketHintsSetting.value = true;

        expect(showMissingTicketHintsSetting.value).toBe(true);
        await vi.waitFor(() =>
            expect(mocks.updateUser).toHaveBeenCalledWith(
                { show_missing_ticket_hints: true },
                { params: { user: 'user-1' } }
            )
        );
    });

    it('puts the switch back and says so if saving fails', async () => {
        mocks.updateUser.mockRejectedValue(new Error('nope'));
        const { showMissingTicketHintsSetting } = await loadJiraModule();

        showMissingTicketHintsSetting.value = true;

        await vi.waitFor(() => expect(mocks.addNotification).toHaveBeenCalled());
        expect(showMissingTicketHintsSetting.value).toBe(false);
    });

    it('keeps a value left in localStorage and moves it onto the account', async () => {
        window.localStorage.setItem(LEGACY_KEY, 'true');

        const { showMissingTicketHintsSetting, adoptLegacyMissingTicketHintsSetting } =
            await loadJiraModule();

        // Honoured on this device straight away, even before the account knows about it
        expect(showMissingTicketHintsSetting.value).toBe(true);
        // Still there: it is only safe to drop once the account actually holds the value
        expect(window.localStorage.getItem(LEGACY_KEY)).toBe('true');

        adoptLegacyMissingTicketHintsSetting();

        await vi.waitFor(() =>
            expect(mocks.updateUser).toHaveBeenCalledWith(
                { show_missing_ticket_hints: true },
                { params: { user: 'user-1' } }
            )
        );
        await vi.waitFor(() => expect(window.localStorage.getItem(LEGACY_KEY)).toBeNull());
    });

    /*
     * Otherwise the setting is lost for good: the key is gone, the account never received it,
     * and the next page load has nothing left to retry from.
     */
    it('keeps the stored value for a later retry when the account will not take it', async () => {
        window.localStorage.setItem(LEGACY_KEY, 'true');
        mocks.updateUser.mockRejectedValue(new Error('nope'));

        const { adoptLegacyMissingTicketHintsSetting } = await loadJiraModule();
        adoptLegacyMissingTicketHintsSetting();

        await vi.waitFor(() => expect(mocks.addNotification).toHaveBeenCalled());
        expect(window.localStorage.getItem(LEGACY_KEY)).toBe('true');
    });

    it('sends nothing for a stored value that matches the default anyway', async () => {
        window.localStorage.setItem(LEGACY_KEY, 'false');

        const { showMissingTicketHintsSetting, adoptLegacyMissingTicketHintsSetting } =
            await loadJiraModule();
        adoptLegacyMissingTicketHintsSetting();

        expect(showMissingTicketHintsSetting.value).toBe(false);
        expect(mocks.updateUser).not.toHaveBeenCalled();
        // Spent, so it stops shadowing the account on the next load
        expect(window.localStorage.getItem(LEGACY_KEY)).toBeNull();
    });

    /*
     * The inverse of the bug this setting was moved to the server to fix: a device still holding
     * the old `false` must not hide dots that another device has since switched on.
     */
    it('lets the account win over a stale stored false', async () => {
        window.localStorage.setItem(LEGACY_KEY, 'false');
        mocks.sharedUser = { id: 'user-1', show_missing_ticket_hints: true };

        const { showMissingTicketHintsSetting } = await loadJiraModule();

        expect(showMissingTicketHintsSetting.value).toBe(true);
    });
});

describe('toExternalSyncBadges', () => {
    it('maps the states that only the server can know', () => {
        const badges = toExternalSyncBadges({
            a: { state: 'synced', issue_key: 'PROJ-1', reason: null },
            b: { state: 'pending', issue_key: 'PROJ-2', reason: null },
            c: { state: 'outdated', issue_key: 'PROJ-3', reason: null },
        });

        expect(badges.a?.state).toBe('synced');
        expect(badges.b?.state).toBe('pending');
        expect(badges.c?.state).toBe('outdated');
    });

    it('puts the ticket in the label, so hovering a dot says which issue', () => {
        const badges = toExternalSyncBadges({
            a: { state: 'synced', issue_key: 'PROJ-1', reason: null },
        });

        expect(badges.a?.label).toContain('PROJ-1');
    });

    it('leaves no_reference to the client side derivation', () => {
        // Taking it from the server is what made a new entry show no dot until a refetch
        const badges = toExternalSyncBadges({
            a: { state: 'no_reference', issue_key: null, reason: null },
        });

        expect(badges).toEqual({});
    });

    it('never marks entries that are not candidates', () => {
        const badges = toExternalSyncBadges({
            a: { state: 'ignored', issue_key: null, reason: 'break' },
            b: { state: 'ignored', issue_key: null, reason: 'still_running' },
            c: { state: 'ignored', issue_key: null, reason: 'before_cutoff' },
        });

        expect(badges).toEqual({});
    });

    it('returns nothing when no statuses have loaded yet', () => {
        expect(toExternalSyncBadges(undefined)).toEqual({});
    });
});

/*
 * The regression these guard: the "no ticket" dot used to come from the server, so a newly
 * created entry had no dot until the status query happened to refetch - in practice not until
 * the page was reloaded. Deriving it from the entries themselves makes it correct immediately,
 * which is only true as long as this stays a pure function of the entry list.
 */
describe('missingReferenceBadges', () => {
    const entry = (over: Partial<MissingReferenceCandidate> = {}): MissingReferenceCandidate => ({
        id: 'entry-1',
        description: 'team standup',
        start: '2026-08-05T09:00:00Z',
        end: '2026-08-05T10:00:00Z',
        type: 'work',
        ...over,
    });

    it('marks a work entry with no ticket', () => {
        const badges = missingReferenceBadges([entry()]);

        expect(badges['entry-1']?.state).toBe('missing-reference');
    });

    it('leaves an entry that has a ticket alone', () => {
        const badges = missingReferenceBadges([entry({ description: 'PROJ-1 fix login' })]);

        expect(badges).toEqual({});
    });

    it('reacts to a description the moment it changes', () => {
        // What the calendar does when an entry is created or edited: recompute from the list
        const withoutKey = entry({ description: 'no ticket' });
        const withKey = entry({ description: 'PROJ-1 now it has one' });

        expect(missingReferenceBadges([withoutKey])['entry-1']?.state).toBe('missing-reference');
        expect(missingReferenceBadges([withKey])['entry-1']).toBeUndefined();
    });

    it('ignores breaks and running entries', () => {
        const badges = missingReferenceBadges([
            entry({ id: 'break', type: 'break' }),
            entry({ id: 'running', end: null }),
        ]);

        expect(badges).toEqual({});
    });

    it('ignores work before the sync cutoff', () => {
        const badges = missingReferenceBadges(
            [
                entry({ id: 'old', start: '2026-08-01T09:00:00Z', end: '2026-08-01T10:00:00Z' }),
                entry({ id: 'new', start: '2026-08-06T09:00:00Z', end: '2026-08-06T10:00:00Z' }),
            ],
            { syncFromDate: '2026-08-05' }
        );

        expect(badges.old).toBeUndefined();
        expect(badges.new?.state).toBe('missing-reference');
    });

    it('honours the project key allow list', () => {
        // Without the allow list "UTF-8" counts as a key, so this entry looks fine
        const utf = [entry({ description: 'fixed UTF-8 handling' })];

        expect(missingReferenceBadges(utf)).toEqual({});
        expect(
            missingReferenceBadges(utf, { allowedProjectKeys: ['PROJ'] })['entry-1']?.state
        ).toBe('missing-reference');
    });

    it('handles an empty list', () => {
        expect(missingReferenceBadges([])).toEqual({});
    });
});

describe('describeSkipReason', () => {
    it('explains why an entry was left out', () => {
        expect(describeSkipReason('before_cutoff')).toContain('already logged');
        expect(describeSkipReason('still_running')).toContain('stopped');
        expect(describeSkipReason('break')).toContain('Breaks');
        expect(describeSkipReason('too_short')).toContain('minute');
    });

    it('falls back to the missing ticket wording for an unknown reason', () => {
        expect(describeSkipReason('something_new')).toContain('No Jira ticket');
    });
});

/*
 * These mirror JiraIssueKeyParserTest on the PHP side. The pattern is duplicated so the edit
 * dialog can show the ticket as you type; if one side changes, these should fail.
 */
describe('detectIssueKey', () => {
    it('finds a key anywhere in the description', () => {
        expect(detectIssueKey('PROJ-123 fixed the login redirect')).toBe('PROJ-123');
        expect(detectIssueKey('looked into PROJ-123 with Sam')).toBe('PROJ-123');
        expect(detectIssueKey('PROJ-123')).toBe('PROJ-123');
    });

    it('returns the first key when several are mentioned', () => {
        expect(detectIssueKey('PROJ-1 blocked by PROJ-2')).toBe('PROJ-1');
    });

    it('returns null when there is no key', () => {
        expect(detectIssueKey('team standup')).toBeNull();
        expect(detectIssueKey('')).toBeNull();
        expect(detectIssueKey(null)).toBeNull();
        expect(detectIssueKey(undefined)).toBeNull();
    });

    it('does not match a lowercase or single letter key', () => {
        expect(detectIssueKey('proj-123 fixed it')).toBeNull();
        expect(detectIssueKey('X-1 fixed it')).toBeNull();
    });

    it('does not treat a longer key as a shorter one', () => {
        expect(detectIssueKey('MYPROJ-12 refactor')).toBe('MYPROJ-12');
    });

    it('matches look-alikes without an allow list, and rejects them with one', () => {
        expect(detectIssueKey('fixed UTF-8 handling')).toBe('UTF-8');
        expect(detectIssueKey('COVID-19 policy update')).toBe('COVID-19');
        expect(detectIssueKey('fixed UTF-8 handling', ['PROJ'])).toBeNull();
    });

    it('skips to the first allowed key', () => {
        expect(detectIssueKey('fixed UTF-8 handling for PROJ-9', ['PROJ'])).toBe('PROJ-9');
    });
});

describe('issueBrowseUrl', () => {
    it('builds the site permalink for an issue', () => {
        expect(issueBrowseUrl('https://acme.atlassian.net', 'OPS-7')).toBe(
            'https://acme.atlassian.net/browse/OPS-7'
        );
    });

    it('does not double the slash when the site url has a trailing one', () => {
        expect(issueBrowseUrl('https://acme.atlassian.net/', 'OPS-7')).toBe(
            'https://acme.atlassian.net/browse/OPS-7'
        );
        expect(issueBrowseUrl('https://acme.atlassian.net///', 'OPS-7')).toBe(
            'https://acme.atlassian.net/browse/OPS-7'
        );
    });

    it('returns null without both parts, so callers can show a reference with no link', () => {
        expect(issueBrowseUrl(null, 'OPS-7')).toBeNull();
        expect(issueBrowseUrl('  ', 'OPS-7')).toBeNull();
        expect(issueBrowseUrl('https://acme.atlassian.net', null)).toBeNull();
        expect(issueBrowseUrl('https://acme.atlassian.net', '')).toBeNull();
    });
});

describe('parseProjectKeys', () => {
    it('accepts commas, whitespace or both, and uppercases', () => {
        expect(parseProjectKeys('PROJ,OPS')).toEqual(['PROJ', 'OPS']);
        expect(parseProjectKeys('proj, ops')).toEqual(['PROJ', 'OPS']);
        expect(parseProjectKeys(' PROJ   OPS ')).toEqual(['PROJ', 'OPS']);
    });

    it('treats empty as no restriction', () => {
        expect(parseProjectKeys('')).toEqual([]);
        expect(parseProjectKeys(null)).toEqual([]);
        expect(parseProjectKeys(undefined)).toEqual([]);
    });
});

/*
 * Mirrors JiraSyncRangeRequest::MAX_RANGE_DAYS. Checked here so the sync dialog's range picker
 * explains itself, rather than the server coming back with a bare 422.
 */
describe('describeInvalidRange', () => {
    it('accepts a range within the limit', () => {
        expect(describeInvalidRange('2026-08-03', '2026-08-09')).toBeNull();
        expect(describeInvalidRange('2026-08-03', '2026-08-03')).toBeNull();
    });

    it('accepts exactly the maximum', () => {
        expect(describeInvalidRange('2026-01-01', '2026-03-04')).toBeNull();
    });

    it('rejects a range longer than the maximum', () => {
        expect(describeInvalidRange('2026-01-01', '2026-03-05')).toContain('62 days');
    });

    it('rejects an end before the start', () => {
        expect(describeInvalidRange('2026-08-09', '2026-08-03')).toContain('end date');
    });

    it('says nothing while the range is half chosen', () => {
        // The picker clears the end as soon as a new start is picked
        expect(describeInvalidRange('2026-08-03', '')).toBeNull();
        expect(describeInvalidRange('', '')).toBeNull();
    });
});
