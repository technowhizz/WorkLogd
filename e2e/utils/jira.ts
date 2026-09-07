import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { PLAYWRIGHT_BASE_URL } from '../../playwright/config';
import { getCurrentUserViaApi, type TestContext } from './api';

/*
 * Helpers for the Jira suite.
 *
 * Everything here talks to the real endpoints. Jira itself is stood in for by
 * App\Service\Jira\FakeJiraClient, which the app binds in place of JiraClient when
 * `JIRA_FAKE_CLIENT=true` and the environment is not production - see config/services.php and
 * AppServiceProvider. That is what makes connecting an account and actually sending worklogs
 * testable here rather than only in PHPUnit: no Atlassian site, no outbound request, and the
 * fake's state lives in the cache so a worklog created by one request is still there for the
 * next one.
 *
 * Deliberately in its own file rather than in utils/api.ts, which several suites share.
 */

/** Any https host will do - the fake never resolves it, but the app insists on a valid one. */
export const JIRA_SITE_URL = 'https://acme.atlassian.net';

export const JIRA_EMAIL = 'sam@acme.test';

/** Accepted by the fake. Nothing about it is special beyond not being rejected. */
export const JIRA_TOKEN = 'a-perfectly-good-token';

/**
 * Rejected by the fake exactly as Jira rejects a bad credential, via
 * FakeJiraClient::REJECTED_TOKEN_PREFIX. Keep the prefix in step with that constant.
 */
export const JIRA_BAD_TOKEN = 'invalid-token-for-testing';

/**
 * Worklogs against this project fail the way a typo'd issue key does, so the dialog's per item
 * failure list can be exercised. Mirrors FakeJiraClient::FAILING_PROJECT_KEY.
 */
export const JIRA_FAILING_ISSUE_KEY = 'FAIL-1';

// ──────────────────────────────────────────────────
// Dates
//
// The fixture registers every user in the host's own timezone, and the app offers to "fix" a
// user whose timezone differs from their device - a modal that covers the page and swallows
// every click. So these tests never change the timezone; they work in local days and convert to
// the instant the API wants, which is the same thing the app itself does.
// ──────────────────────────────────────────────────

export function localDate(offsetDays = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + offsetDays);
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * A local date and hour as the UTC instant the time entry endpoint takes. Built through Date so
 * the offset is the host's real one, including whether it was in daylight saving on that day -
 * hard coding `T09:00:00Z` puts work on the day before for anyone far enough west.
 *
 * The format is strictly Y-m-d\TH:i:s\Z, so the milliseconds toISOString adds come back off.
 */
export function localTimestamp(date: string, hour: number): string {
    const local = new Date(`${date}T${String(hour).padStart(2, '0')}:00:00`);
    return local.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** 0 = Sunday, 1 = Monday. The week starts on Monday, so 1 is the first visible column. */
export function localWeekday(): number {
    return new Date().getDay();
}

/**
 * The Monday of the week currently on screen, and the day `offset` places after it.
 * The calendar's week starts Monday (the User model's default `week_start`).
 */
export function visibleWeekDay(offset: number): string {
    const daysSinceMonday = (localWeekday() + 6) % 7;
    return localDate(offset - daysSinceMonday);
}

// ──────────────────────────────────────────────────
// Organization and connection setup
// ──────────────────────────────────────────────────

/** Turns the integration on (or off) for the whole organization, as an admin would. */
export async function setJiraSiteUrlViaApi(ctx: TestContext, siteUrl: string | null) {
    const response = await ctx.request.put(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}`,
        { data: { jira_site_url: siteUrl } }
    );
    expect(response.status()).toBe(200);
}

/**
 * Links the current user's Jira account.
 *
 * Goes through the real OAuth connect route. With JIRA_FAKE_CLIENT on it short circuits the trip
 * to Atlassian and comes straight back connected, so this exercises the route the button uses
 * rather than a test-only side door.
 */
export async function connectJiraViaApi(ctx: TestContext) {
    const response = await ctx.request.get(`${PLAYWRIGHT_BASE_URL}/integrations/jira/connect`);
    expect([200, 302]).toContain(response.status());

    const connection = await ctx.request.get(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/jira/connection`
    );
    expect(connection.status()).toBe(200);

    return (await connection.json()).data as {
        is_configured: boolean;
        is_connected: boolean;
        display_name: string | null;
        email: string | null;
        site_url: string | null;
        sync_from_date: string | null;
    };
}

/** The cutoff before which work is treated as already logged. `null` clears it. */
export async function setJiraSyncFromDateViaApi(ctx: TestContext, syncFromDate: string | null) {
    const response = await ctx.request.put(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/jira/settings`,
        { data: { sync_from_date: syncFromDate } }
    );
    expect(response.status()).toBe(200);
}

export type JiraSyncState = 'synced' | 'pending' | 'outdated' | 'no_reference' | 'ignored';

export async function getJiraSyncStatusViaApi(ctx: TestContext, start: string, end: string) {
    const response = await ctx.request.get(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/jira/sync-status?start=${start}&end=${end}`
    );
    expect(response.status()).toBe(200);
    return (await response.json()).data as Record<
        string,
        { state: JiraSyncState; issue_key: string | null; reason: string | null }
    >;
}

// ──────────────────────────────────────────────────
// Per user settings
//
// utils/api.ts has updateUserProfileViaApi, but it only forwards the fields the profile suite
// needs. These two are ours, and that file belongs to another suite.
// ──────────────────────────────────────────────────

/**
 * The "no ticket" dots. A persisted per user setting rather than localStorage, so it has to be
 * set through the API and is picked up on the next page load.
 */
export async function setShowMissingTicketHintsViaApi(ctx: TestContext, value: boolean) {
    await updateUserViaApi(ctx, { show_missing_ticket_hints: value });
}

/**
 * The response behind a user setting saved from the UI rather than through the API above.
 *
 * The controls that write to the user - the "no ticket" checkbox among them - save
 * optimistically: the setter fires the request and does not await it, so a `page.goto` on the
 * following line cancels it and the setting is silently lost. Pair the click with this.
 */
export function waitForUserSaved(page: Page) {
    return page.waitForResponse(
        (response) =>
            /\/api\/v1\/users\/[^/]+$/.test(new URL(response.url()).pathname) &&
            response.request().method() === 'PUT' &&
            response.status() === 200
    );
}

/** Fewer than seven columns on the calendar, which the default sync range has to follow. */
export async function setCalendarWeekDaysViaApi(ctx: TestContext, days: number) {
    await updateUserViaApi(ctx, { calendar_week_days: days });
}

async function updateUserViaApi(ctx: TestContext, data: Record<string, unknown>) {
    const user = await getCurrentUserViaApi(ctx);
    const response = await ctx.request.put(`${PLAYWRIGHT_BASE_URL}/api/v1/users/${user.id}`, {
        data,
    });
    expect(response.status()).toBe(200);
}

// ──────────────────────────────────────────────────
// Driving the sync from the API, to set up state a later assertion is about
// ──────────────────────────────────────────────────

/**
 * Runs a sync the way the dialog does and waits for it to finish. Used to *arrange* worklogs
 * that a later plan can update, delete or leave unchanged - the dialog's own confirm path is
 * covered through the UI instead.
 */
export async function runJiraSyncViaApi(ctx: TestContext, start: string, end: string) {
    const response = await ctx.request.post(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/jira/sync`,
        { data: { start, end } }
    );
    expect(response.status()).toBe(200);
    const runId = (await response.json()).data.id as string;

    // The dev queue is synchronous, so this is normally already finished. Polled anyway so the
    // helper does not quietly depend on that.
    for (let attempt = 0; attempt < 40; attempt++) {
        const runResponse = await ctx.request.get(
            `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/jira/sync-runs/${runId}`
        );
        expect(runResponse.status()).toBe(200);
        const run = (await runResponse.json()).data as {
            status: string;
            error: string | null;
            results: unknown[];
        };
        if (run.status === 'completed' || run.status === 'failed') {
            expect(run.status, `sync run failed: ${run.error}`).toBe('completed');
            return run;
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    throw new Error('Jira sync run did not finish');
}

// ──────────────────────────────────────────────────
// UI
// ──────────────────────────────────────────────────

export function jiraSyncButton(page: Page): Locator {
    return page.getByTestId('jira_sync_button');
}

export function jiraSyncDialog(page: Page): Locator {
    return page.getByTestId('jira_sync_dialog');
}

/** A plan row, keyed on the action so a test says what it means. */
export function planRow(page: Page, action: 'create' | 'update' | 'delete'): Locator {
    return page.locator(`[data-testid="jira_sync_plan_row"][data-action="${action}"]`);
}

/**
 * Opens the dialog from the calendar toolbar and waits for the first plan to land, so a caller
 * never asserts against a half rendered dialog.
 */
export async function openJiraSyncDialog(page: Page) {
    await expect(jiraSyncButton(page)).toBeEnabled();
    await jiraSyncButton(page).click();
    await expect(jiraSyncDialog(page)).toBeVisible();
    await expect(page.getByTestId('jira_sync_loading')).toHaveCount(0);
}

/**
 * Dismisses the dialog by clicking the overlay rather than pressing Escape, because that is the
 * path the reopen regression was found on.
 */
export async function dismissJiraSyncDialogByClickingOutside(page: Page) {
    // Top left corner: the dialog is centred, so this always lands on the overlay
    await page.mouse.click(4, 4);
    await expect(jiraSyncDialog(page)).toHaveCount(0);
}

/** The start/end a sync-preview request asked for, so the default range can be asserted exactly. */
export function previewRangeOf(url: string): { start: string | null; end: string | null } {
    const query = new URL(url).searchParams;
    return { start: query.get('start'), end: query.get('end') };
}

export function isSyncPreviewRequest(url: string): boolean {
    return url.includes('/jira/sync-preview');
}
