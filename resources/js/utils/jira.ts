import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { api, type JiraSyncEntryStatus } from '@/packages/api/src';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { getCurrentUserId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import type {
    ExternalSyncBadge,
    ExternalSyncBadges,
} from '@/packages/ui/src/TimeEntry/externalSyncTypes';

/**
 * Whether an administrator has pointed this organization at a Jira site. Everything user facing
 * gates on this, so an organization that does not use Jira never sees the integration.
 */
export function isJiraEnabled(): boolean {
    const page = usePage<{
        jira_enabled: boolean;
    }>();

    return page.props.jira_enabled === true;
}

/**
 * The Inertia shared props carry the persisted value, the same way `week_start`, `timezone`,
 * `calendar_week_days` and `no_project_color` do. Created at module scope like utils/useUser.ts:
 * `usePage()` only wraps the adapter's page ref in computeds, so it is safe to call before the
 * app is mounted as long as nothing reads `props` until then.
 */
const page = usePage<{
    auth: {
        // Optional because a session whose shared props predate this deploy has no such key
        user: { show_missing_ticket_hints?: boolean };
    };
}>();

const LEGACY_STORAGE_KEY = 'solidtime:jira-missing-ticket-hints';

/**
 * Reads the value this setting used to be kept in, so nobody who had already turned the dots on
 * loses them. Read at import time because it needs nothing but localStorage; the push to the
 * server happens later, from adoptLegacyMissingTicketHintsSetting().
 *
 * Deliberately does NOT remove the key - that only happens once the account has the value (see
 * clearLegacyLocalStorageSetting). Dropping it here would lose the setting for good if the
 * request that carries it to the server then failed.
 */
function readLegacyLocalStorageSetting(): boolean | null {
    try {
        const stored = window?.localStorage?.getItem(LEGACY_STORAGE_KEY);
        if (stored === null || stored === undefined) {
            return null;
        }
        return stored === 'true';
    } catch {
        // Private browsing modes and blocked storage both throw rather than returning null
        return null;
    }
}

function clearLegacyLocalStorageSetting(): void {
    try {
        window?.localStorage?.removeItem(LEGACY_STORAGE_KEY);
    } catch {
        // Nothing to do - a storage that will not delete will not have been read either
    }
}

const legacyValue = readLegacyLocalStorageSetting();

/**
 * What this tab believes the value to be, or null to defer to the shared props.
 *
 * Kept because Inertia restores shared props from `history.state` on back/forward without asking
 * the server, so a page that was open when the setting changed would otherwise flip back to the
 * old value. The same caveat applies to `no_project_color` and friends - see
 * packages/ui/src/utils/settings.ts - but those have no in-app control, and this one does.
 */
/*
 * Only an enabled legacy value seeds this. A leftover `false` must defer to the account instead,
 * or a device that still had the old key would hide the dots even though another device had
 * since switched them on - the very bug this setting moved to the server to fix.
 */
const localValue = ref<boolean | null>(legacyValue === true ? true : null);

async function persist(value: boolean): Promise<boolean> {
    const previous = localValue.value;
    localValue.value = value;

    try {
        await api.updateUser(
            { show_missing_ticket_hints: value },
            { params: { user: getCurrentUserId() } }
        );
        return true;
    } catch {
        localValue.value = previous;
        useNotificationsStore().addNotification(
            'error',
            'Failed to save setting',
            'Please try again later.'
        );
        return false;
    }
}

/**
 * Whether to mark work entries that carry no Jira issue key. Off by default: on a board where
 * only some work is ticketed it is noise, and it is only useful to the people who want it.
 *
 * A per-user setting on the server rather than localStorage, because it used to be the latter and
 * signing in on a second device silently turned the dots back off. Shared by the calendar, the
 * time list and the timesheet, so it follows you between them as well as between devices.
 *
 * Writing to it saves optimistically and reverts if the request fails.
 */
export const showMissingTicketHintsSetting = computed<boolean>({
    get() {
        if (localValue.value !== null) {
            return localValue.value;
        }
        return page.props?.auth?.user?.show_missing_ticket_hints === true;
    },
    set(value) {
        void persist(value);
    },
});

// The layout that calls the function below mounts once per Inertia visit, and this module
// outlives them all, so the one-shot guard has to live out here
let legacyAdopted = false;

/**
 * Moves a value left over in localStorage onto the account, once, after the app has mounted -
 * persisting needs the current user id, which comes from the shared props.
 *
 * Only an enabled setting is worth sending: the server already defaults to off, so pushing a
 * stored `false` would be a request that changes nothing.
 */
export function adoptLegacyMissingTicketHintsSetting(): void {
    if (legacyValue === null || legacyAdopted) {
        return;
    }
    legacyAdopted = true;

    // A stored `false` is what the server already defaults to, so there is nothing to send -
    // but the key has still served its purpose and should stop shadowing the account.
    if (legacyValue === false || page.props?.auth?.user?.show_missing_ticket_hints === true) {
        clearLegacyLocalStorageSetting();
        return;
    }

    // Only once the account actually holds the value, so a failed request leaves the key in
    // place for the next page load to retry instead of silently dropping the setting.
    void persist(true).then((saved) => {
        if (saved) {
            clearLegacyLocalStorageSetting();
        }
    });
}

const STATE_LABELS: Record<string, string> = {
    synced: 'Logged in Jira',
    pending: 'Not logged in Jira yet',
    outdated: 'Changed since it was logged in Jira',
    no_reference: 'No Jira ticket in the description',
};

const REASON_LABELS: Record<string, string> = {
    before_cutoff: 'Before your Jira start date, treated as already logged',
    still_running: 'Still running, it will sync once stopped',
    break: 'Breaks are not logged to Jira',
    too_short: 'Under a minute, which Jira will not accept',
};

/**
 * Maps the server's sync states onto the provider agnostic badges packages/ui renders.
 *
 * Only synced / pending / outdated live here, because only those need the server - it alone
 * knows what has been logged. "No ticket" is deliberately NOT one of them: see
 * missingReferenceBadges below.
 */
export function toExternalSyncBadges(
    statuses: Record<string, JiraSyncEntryStatus> | undefined
): ExternalSyncBadges {
    const badges: ExternalSyncBadges = {};
    if (!statuses) {
        return badges;
    }

    for (const [timeEntryId, status] of Object.entries(statuses)) {
        const label = STATE_LABELS[status.state];
        // no_reference and ignored are both handled client side, from the entry itself
        if (!label || status.state === 'no_reference' || status.state === 'ignored') {
            continue;
        }

        badges[timeEntryId] = {
            state: status.state as ExternalSyncBadge['state'],
            label: status.issue_key ? `${status.issue_key} — ${label}` : label,
        };
    }

    return badges;
}

/** The minimum of a time entry needed to decide whether it is missing a ticket. */
export interface MissingReferenceCandidate {
    id: string;
    description?: string | null;
    start: string;
    end?: string | null;
    type?: string;
}

/**
 * Work entries with no ticket in their description, derived entirely on the client.
 *
 * This used to come from the server, which meant a newly created entry had no dot until the
 * status query happened to refetch - in practice, until the page was reloaded. Nothing here
 * needs the server: the rules mirror JiraWorklogGrouper, and the description is already on
 * screen. Deriving it makes the dot correct the instant the entry list changes, and costs no
 * request at all for someone who has not connected an account.
 */
export function missingReferenceBadges(
    timeEntries: MissingReferenceCandidate[],
    options: { allowedProjectKeys?: string[]; syncFromDate?: string | null } = {}
): ExternalSyncBadges {
    const badges: ExternalSyncBadges = {};

    for (const entry of timeEntries) {
        // Breaks are not work, and a running entry has no final duration yet
        if (entry.type === 'break' || !entry.end) {
            continue;
        }
        // Work before the cutoff is treated as already logged elsewhere
        if (options.syncFromDate) {
            const workDate = getLocalizedDayJs(entry.start).format('YYYY-MM-DD');
            if (workDate < options.syncFromDate) {
                continue;
            }
        }
        if (detectIssueKey(entry.description, options.allowedProjectKeys ?? []) !== null) {
            continue;
        }

        badges[entry.id] = {
            state: 'missing-reference',
            label: STATE_LABELS.no_reference!,
        };
    }

    return badges;
}

/**
 * Jira project keys start with a letter and are at least two characters, then a hyphen and the
 * issue number.
 *
 * Deliberately kept in step with JiraIssueKeyParser::ISSUE_KEY_PATTERN. It is duplicated rather
 * than fetched because the edit dialog shows the detected ticket as you type, and a round trip
 * per keystroke to say something this cheap to compute would be absurd. The server remains the
 * authority: it re-parses at sync time, and this only ever affects what is displayed.
 */
const ISSUE_KEY_PATTERN = /\b([A-Z][A-Z0-9]+)-\d+\b/g;

/**
 * The ticket a description refers to, or null. First match wins, and an organization's project
 * key allow list narrows it - without one, `UTF-8` and `COVID-19` are the same shape as a key.
 */
export function detectIssueKey(
    description: string | null | undefined,
    allowedProjectKeys: string[] = []
): string | null {
    const text = (description ?? '').trim();
    if (text === '') {
        return null;
    }

    for (const match of text.matchAll(ISSUE_KEY_PATTERN)) {
        if (allowedProjectKeys.length === 0 || allowedProjectKeys.includes(match[1]!)) {
            return match[0];
        }
    }

    return null;
}

/**
 * Where an issue lives on the organization's Jira site, ex. https://acme.atlassian.net/browse/OPS-7.
 *
 * `/browse/<key>` is Jira's own permalink for an issue and resolves whatever project it belongs
 * to. The stored site URL is trimmed of trailing slashes, which an admin can easily leave on and
 * which would otherwise produce a double slash.
 */
export function issueBrowseUrl(
    siteUrl: string | null | undefined,
    issueKey: string | null | undefined
): string | null {
    const site = (siteUrl ?? '').trim().replace(/\/+$/, '');
    const key = (issueKey ?? '').trim();
    if (site === '' || key === '') {
        return null;
    }

    return `${site}/browse/${encodeURIComponent(key)}`;
}

/** The longest range the sync-status endpoint accepts, mirroring JiraSyncRangeRequest. */
export const MAX_STATUS_DAYS = 62;

/**
 * The local date range to ask the status endpoint about, derived from whatever entries a page
 * has loaded and clamped to the newest MAX_STATUS_DAYS the endpoint accepts. Entries older than
 * that simply carry no indicator, which reads as "not known" rather than as "nothing to do".
 */
export function statusRangeForEntries(entries: { start: string }[]): {
    start: string | null;
    end: string | null;
} {
    if (entries.length === 0) {
        return { start: null, end: null };
    }
    const dates = entries.map((entry) => getLocalizedDayJs(entry.start).format('YYYY-MM-DD'));
    const end = dates.reduce((a, b) => (a > b ? a : b));
    const earliest = dates.reduce((a, b) => (a < b ? a : b));
    const clamped = getLocalizedDayJs(end).subtract(MAX_STATUS_DAYS, 'day').format('YYYY-MM-DD');

    return { start: earliest > clamped ? earliest : clamped, end };
}

/** Parses the organization's comma separated allow list, matching JiraConfig::parseProjectKeys. */
export function parseProjectKeys(value: string | null | undefined): string[] {
    return (value ?? '')
        .split(/[\s,]+/)
        .map((key) => key.trim().toUpperCase())
        .filter((key) => key !== '');
}

/**
 * Longest range a single sync may cover. Mirrors JiraSyncRangeRequest::MAX_RANGE_DAYS - checked
 * here as well so choosing a silly range says so, rather than coming back as a raw 422.
 */
export const MAX_SYNC_RANGE_DAYS = 62;

/** Why a chosen range cannot be synced, or null if it is fine. */
export function describeInvalidRange(startDate: string, endDate: string): string | null {
    if (startDate === '' || endDate === '') {
        return null;
    }
    if (endDate < startDate) {
        return 'The end date must not be before the start date.';
    }

    const days = getLocalizedDayJs(endDate).diff(getLocalizedDayJs(startDate), 'day');
    if (days > MAX_SYNC_RANGE_DAYS) {
        return `A single sync can cover at most ${MAX_SYNC_RANGE_DAYS} days.`;
    }

    return null;
}

/** Human readable form of a skip reason, for the preview dialog's skipped list. */
export function describeSkipReason(reason: string): string {
    return REASON_LABELS[reason] ?? 'No Jira ticket in the description';
}
