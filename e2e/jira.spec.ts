import { test, expect } from '../playwright/fixtures';
import { PLAYWRIGHT_BASE_URL } from '../playwright/config';
import {
    setupTestContext,
    createTimeEntryWithTimestampsViaApi,
    type TestContext,
} from './utils/api';
import { scrollIntoViewCentred } from './utils/scroll';
import {
    JIRA_BAD_TOKEN,
    JIRA_EMAIL,
    JIRA_FAILING_ISSUE_KEY,
    JIRA_SITE_URL,
    JIRA_TOKEN,
    connectJiraViaApi,
    dismissJiraSyncDialogByClickingOutside,
    getJiraSyncStatusViaApi,
    isSyncPreviewRequest,
    jiraSyncButton,
    jiraSyncDialog,
    openJiraSyncDialog,
    planRow,
    previewRangeOf,
    runJiraSyncViaApi,
    setCalendarWeekDaysViaApi,
    setJiraSiteUrlViaApi,
    setJiraSyncFromDateViaApi,
    setShowMissingTicketHintsViaApi,
    localDate,
    localTimestamp,
    localWeekday,
    visibleWeekDay,
} from './utils/jira';
import type { Page } from '@playwright/test';

/*
 * The whole Jira experience, end to end.
 *
 * Jira itself is App\Service\Jira\FakeJiraClient - an in-process stand-in the app binds in place
 * of JiraClient when JIRA_FAKE_CLIENT is set and the environment is not production. Its state
 * lives in the cache, so a worklog created by one request is still there for the next, which is
 * what lets these tests sync a range twice and get an update the second time. Nothing here makes
 * an outbound request, and the seam cannot be switched on in production - see
 * FakeJiraClientBindingTest.
 */

const SITE_URL = JIRA_SITE_URL;

async function deleteTimeEntryViaApi(ctx: TestContext, id: string) {
    const response = await ctx.request.delete(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/time-entries/${id}`
    );
    expect(response.status()).toBe(204);
}

/**
 * A member of an organization that uses Jira. The user's timezone is deliberately left as the
 * fixture registered it - the host's - because changing it raises the app's "timezone mismatch"
 * modal, which covers the page and swallows every subsequent click.
 */
async function jiraOrganization(page: Page): Promise<TestContext> {
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);
    return ctx;
}

// ──────────────────────────────────────────────────
// The organization setting, which gates everything else
// ──────────────────────────────────────────────────

test('test that the jira card is hidden until an admin configures a site', async ({ page }) => {
    // Arrange
    await setupTestContext(page);

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');

    // Assert
    await expect(page.getByRole('heading', { name: 'Jira', exact: true })).toHaveCount(0);
});

test('test that the calendar shows no jira button until an admin configures a site', async ({
    page,
}) => {
    // Arrange
    await setupTestContext(page);

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');

    // Assert
    // jira_enabled is false in the shared props, so the toolbar slot is not filled at all
    await expect(page.getByTestId('calendar_view')).toBeVisible();
    await expect(jiraSyncButton(page)).toHaveCount(0);
});

test('test that an admin can set and clear the organization jira site', async ({ page }) => {
    // Arrange
    const ctx = await setupTestContext(page);
    await page.goto(PLAYWRIGHT_BASE_URL + '/teams/' + ctx.orgId);

    // Act
    const siteUrlInput = page.getByTestId('organization_jira_site_url');
    await expect(siteUrlInput).toBeEditable();
    await siteUrlInput.fill(SITE_URL);
    await page.getByTestId('organization_jira_submit').click();

    // Assert
    await page.reload();
    await expect(page.getByTestId('organization_jira_site_url')).toHaveValue(SITE_URL);
    // And the integration is now switched on for everybody in the organization
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await expect(page.getByRole('heading', { name: 'Jira', exact: true })).toBeVisible();

    // Act: clearing it turns the integration off again
    await page.goto(PLAYWRIGHT_BASE_URL + '/teams/' + ctx.orgId);
    await page.getByTestId('organization_jira_site_url').fill('');
    await page.getByTestId('organization_jira_submit').click();
    await page.reload();

    // Assert
    await expect(page.getByTestId('organization_jira_site_url')).toHaveValue('');
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await expect(page.getByRole('heading', { name: 'Jira', exact: true })).toHaveCount(0);
});

test('test that a plain http jira site is rejected', async ({ page }) => {
    // Arrange
    const ctx = await setupTestContext(page);

    // Act
    // The API token travels on every request, so plaintext must not be accepted
    const response = await ctx.request.put(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}`,
        { data: { jira_site_url: 'http://acme.atlassian.net' } }
    );

    // Assert
    expect(response.status()).toBe(422);
});

// ──────────────────────────────────────────────────
// Connecting and disconnecting an account
// ──────────────────────────────────────────────────

test('test that the jira card asks for credentials once a site is configured', async ({ page }) => {
    // Arrange
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');

    // Assert
    await expect(page.getByTestId('jira_email')).toBeVisible();
    await expect(page.getByTestId('jira_api_token')).toBeVisible();
    // Nothing to disconnect, and no sync cutoff, until an account is actually linked
    await expect(page.getByTestId('jira_disconnect')).toHaveCount(0);
    await expect(page.getByTestId('jira_sync_from_date')).toHaveCount(0);
    // The missing-ticket toggle is not gated on a connection - it needs no Jira account
    await expect(page.getByTestId('jira_missing_ticket_toggle')).toBeVisible();
});

test('test that the connect button stays disabled until both fields are filled', async ({
    page,
}) => {
    // Arrange
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');

    // Act & Assert
    const connect = page.getByTestId('jira_connect');
    await expect(connect).toBeDisabled();

    await page.getByTestId('jira_email').fill(JIRA_EMAIL);
    await expect(connect).toBeDisabled();

    await page.getByTestId('jira_api_token').fill(JIRA_TOKEN);
    await expect(connect).toBeEnabled();
});

test('test that connecting a jira account from the profile card links it', async ({ page }) => {
    // Arrange
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');

    // Act
    await expect(page.getByTestId('jira_email')).toBeEditable();
    await page.getByTestId('jira_email').fill(JIRA_EMAIL);
    await page.getByTestId('jira_api_token').fill(JIRA_TOKEN);
    await page.getByTestId('jira_connect').click();

    // Assert
    await expect(page.getByTestId('jira_connected_account')).toBeVisible();
    await expect(page.getByTestId('jira_connected_account')).toContainText(JIRA_EMAIL);
    await expect(page.getByTestId('jira_connected_account')).toContainText(SITE_URL);
    // The credentials form gives way to the things only a connected account has
    await expect(page.getByTestId('jira_api_token')).toHaveCount(0);
    await expect(page.getByTestId('jira_disconnect')).toBeVisible();
    await expect(page.getByTestId('jira_sync_from_date')).toBeVisible();

    // Assert: and it survives a reload, so it really was stored
    await page.reload();
    await expect(page.getByTestId('jira_connected_account')).toContainText(JIRA_EMAIL);
});

test('test that bad jira credentials are rejected and nothing is stored', async ({ page }) => {
    // Arrange
    // The credentials are checked before the connection is saved, so a wrong token has to leave
    // the card exactly as it was rather than half connected.
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');

    // Act
    await expect(page.getByTestId('jira_email')).toBeEditable();
    await page.getByTestId('jira_email').fill(JIRA_EMAIL);
    await page.getByTestId('jira_api_token').fill(JIRA_BAD_TOKEN);
    await page.getByTestId('jira_connect').click();

    // Assert
    await expect(page.getByText('Failed to connect Jira account')).toBeVisible();
    await expect(page.getByTestId('jira_connected_account')).toHaveCount(0);
    await expect(page.getByTestId('jira_disconnect')).toHaveCount(0);

    // Assert: nothing was stored, so a reload still shows the empty form
    await page.reload();
    await expect(page.getByTestId('jira_email')).toBeVisible();
    await expect(page.getByTestId('jira_connected_account')).toHaveCount(0);

    // Act: the good token then works, from the same card
    await expect(page.getByTestId('jira_api_token')).toBeEditable();
    await page.getByTestId('jira_email').fill(JIRA_EMAIL);
    await page.getByTestId('jira_api_token').fill(JIRA_TOKEN);
    await page.getByTestId('jira_connect').click();

    // Assert
    await expect(page.getByTestId('jira_connected_account')).toBeVisible();
});

test('test that disconnecting jira asks first and then unlinks the account', async ({ page }) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await expect(page.getByTestId('jira_connected_account')).toBeVisible();

    // Act: the confirmation can be backed out of, because disconnecting loses the worklog history
    await page.getByTestId('jira_disconnect').click();
    await expect(page.getByTestId('jira_disconnect_cancel')).toBeVisible();
    await page.getByTestId('jira_disconnect_cancel').click();

    // Assert
    await expect(page.getByTestId('jira_connected_account')).toBeVisible();

    // Act
    await page.getByTestId('jira_disconnect').click();
    await page.getByTestId('jira_disconnect_confirm').click();

    // Assert
    await expect(page.getByTestId('jira_email')).toBeVisible();
    await expect(page.getByTestId('jira_connected_account')).toHaveCount(0);
    await expect(page.getByTestId('jira_sync_from_date')).toHaveCount(0);

    // Assert: and the sync button goes back to being unusable
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(jiraSyncButton(page)).toBeDisabled();
});

// ──────────────────────────────────────────────────
// The sync button
// ──────────────────────────────────────────────────

test('test that the jira sync button is disabled until an account is connected', async ({
    page,
}) => {
    // Arrange
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');

    // Assert
    await expect(jiraSyncButton(page)).toBeVisible();
    await expect(jiraSyncButton(page)).toBeDisabled();
});

test('test that hovering the disabled jira button explains why', async ({ page }) => {
    // Arrange
    // The button's base class sets disabled:pointer-events-none, so this only works because the
    // trigger is a wrapping span rather than the button itself
    const ctx = await setupTestContext(page);
    await setJiraSiteUrlViaApi(ctx, SITE_URL);
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(jiraSyncButton(page)).toBeDisabled();

    // Act
    await jiraSyncButton(page).hover({ force: true });

    // Assert
    await expect(page.getByTestId('jira_sync_button_tooltip').first()).toContainText(
        'Connect your Jira account'
    );
});

test('test that the jira sync button is enabled once an account is connected', async ({ page }) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');

    // Assert
    await expect(jiraSyncButton(page)).toBeEnabled();
});

// ──────────────────────────────────────────────────
// The sync dialog
// ──────────────────────────────────────────────────

test('test that the sync dialog defaults to the visible calendar week', async ({ page }) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(jiraSyncButton(page)).toBeEnabled();

    // Act
    const previewRequest = page.waitForRequest((request) => isSyncPreviewRequest(request.url()));
    await jiraSyncButton(page).click();

    // Assert
    // Monday to Sunday, the days actually on screen. The calendar's own range is half open, so
    // a dialog that forgot to subtract a day would ask for the Monday after as well.
    expect(previewRangeOf((await previewRequest).url())).toEqual({
        start: visibleWeekDay(0),
        end: visibleWeekDay(6),
    });
    await expect(jiraSyncDialog(page)).toBeVisible();
});

test('test that the sync dialog follows a calendar showing fewer than seven days', async ({
    page,
}) => {
    // Arrange
    // Someone on a Monday to Friday calendar must not have their weekend swept into a sync they
    // cannot see. Five columns, so the range has to stop on the Friday.
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    await setCalendarWeekDaysViaApi(ctx, 5);
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(jiraSyncButton(page)).toBeEnabled();

    // Act
    const previewRequest = page.waitForRequest((request) => isSyncPreviewRequest(request.url()));
    await jiraSyncButton(page).click();

    // Assert
    expect(previewRangeOf((await previewRequest).url())).toEqual({
        start: visibleWeekDay(0),
        end: visibleWeekDay(4),
    });
});

/*
 * Regression. reset() clears the plan but leaves the range refs alone, so reopening on the same
 * week re-seeds the identical range and a watcher on the range alone never fires again - the
 * dialog then sat on the discarded plan and claimed there was nothing to send. `show` is watched
 * alongside the range for exactly this. Dismissed by clicking outside rather than with Escape,
 * because that is the path it was found on.
 */
test('test that reopening the sync dialog after clicking outside loads a fresh plan', async ({
    page,
}) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 something to send',
    });
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');

    let previews = 0;
    page.on('request', (request) => {
        if (isSyncPreviewRequest(request.url())) {
            previews++;
        }
    });

    // Act
    await openJiraSyncDialog(page);

    // Assert
    await expect(planRow(page, 'create')).toHaveCount(1);
    expect(previews).toBe(1);

    // Act
    await dismissJiraSyncDialogByClickingOutside(page);
    await openJiraSyncDialog(page);

    // Assert: a second plan was fetched, and the dialog shows it rather than "Nothing to send"
    await expect(page.getByTestId('jira_sync_nothing_to_send')).toHaveCount(0);
    await expect(planRow(page, 'create')).toHaveCount(1);
    await expect(page.getByTestId('jira_sync_confirm')).toBeEnabled();
    expect(previews).toBe(2);
});

test('test that changing the range spins over the existing plan without collapsing it', async ({
    page,
}) => {
    // Arrange
    // The dialog keeps the previous plan on screen, dimmed, while it works out a new one, so the
    // footer does not jump under the cursor. The second preview is held open to make the loading
    // state observable at all.
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 first plan',
    });

    let previews = 0;
    await page.route('**/jira/sync-preview*', async (route) => {
        previews++;
        if (previews > 1) {
            await new Promise((resolve) => setTimeout(resolve, 2000));
        }
        await route.continue();
    });

    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await openJiraSyncDialog(page);
    await expect(page.getByTestId('jira_sync_plan')).toBeVisible();
    const heightBefore = (await page.getByTestId('jira_sync_body').boundingBox())!.height;

    // Act: narrow the range to today only, from the picker's own shortcut
    await page.getByTestId('jira_sync_range').getByRole('button').click();
    await page.getByRole('button', { name: 'Today', exact: true }).click();

    // Assert: spinner over a plan that is still there, and nothing confirmable while it loads
    await expect(page.getByTestId('jira_sync_loading')).toBeVisible();
    await expect(page.getByTestId('jira_sync_plan')).toBeVisible();
    await expect(page.getByTestId('jira_sync_confirm')).toBeDisabled();
    const heightDuring = (await page.getByTestId('jira_sync_body').boundingBox())!.height;
    expect(Math.abs(heightDuring - heightBefore)).toBeLessThanOrEqual(1);

    // Assert: and the narrowed range is what was actually asked for
    await expect(page.getByTestId('jira_sync_loading')).toHaveCount(0, { timeout: 15000 });
    await expect(page.getByTestId('jira_sync_confirm')).toBeEnabled();
    await expect(planRow(page, 'create')).toHaveCount(1);
});

test('test that the sync dialog previews creates, updates, deletes, unchanged and skipped', async ({
    page,
}) => {
    // Arrange
    // Reconciliation, not a ledger: the plan is recomputed from the entries every time, so all
    // five outcomes can be produced by syncing once and then moving the entries around.
    test.setTimeout(90000);
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();

    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 alpha',
    });
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 10),
        end: localTimestamp(today, 11),
        description: 'PROJ-2 beta',
    });
    const gamma = await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 11),
        end: localTimestamp(today, 12),
        description: 'PROJ-3 gamma',
    });

    // Everything above is now logged in the fake Jira, by an earlier HTTP request
    await runJiraSyncViaApi(ctx, today, today);

    // A second entry on the same ticket, day and description grows that group: an update
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 14),
        end: localTimestamp(today, 15),
        description: 'PROJ-1 alpha',
    });
    // A ticket nothing has been logged against: a create
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 13),
        end: localTimestamp(today, 14),
        description: 'PROJ-4 delta',
    });
    // Its entry is gone, so the worklog solidtime made for it has to go too: a delete
    await deleteTimeEntryViaApi(ctx, gamma.id);
    // No ticket at all: skipped, and called out so it can be fixed
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 15),
        end: localTimestamp(today, 16),
        description: 'no ticket on this one',
    });
    // PROJ-2 beta is untouched: unchanged

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await openJiraSyncDialog(page);

    // Assert
    await expect(planRow(page, 'create')).toHaveAttribute('data-issue-key', 'PROJ-4');
    await expect(planRow(page, 'update')).toHaveAttribute('data-issue-key', 'PROJ-1');
    await expect(planRow(page, 'delete')).toHaveAttribute('data-issue-key', 'PROJ-3');
    await expect(page.getByTestId('jira_sync_plan_row')).toHaveCount(3);
    await expect(page.getByTestId('jira_sync_unchanged')).toContainText(
        '1 worklog already up to date'
    );
    await expect(page.getByTestId('jira_sync_missing_ticket')).toContainText(
        'no ticket on this one'
    );
    // Deleting is the only thing here that destroys work in Jira, so it is called out on its own
    await expect(page.getByTestId('jira_sync_delete_warning')).toBeVisible();
    await expect(page.getByTestId('jira_sync_confirm')).toContainText('Sync 3 to Jira');
    // 1h onto PROJ-4 plus PROJ-1 growing to 2h. The deleted hour is coming back out of Jira, so
    // it is not part of what is being logged and must not be added on top.
    await expect(page.getByTestId('jira_sync_total')).toHaveText('Total 3h 00min');

    // Act: carry it out
    await page.getByTestId('jira_sync_confirm').click();

    // Assert
    await expect(page.getByTestId('jira_sync_success')).toBeVisible({ timeout: 30000 });
    // The picker gives way to a plain label once a run has started
    await expect(page.getByTestId('jira_sync_range')).toHaveCount(0);
    await expect(jiraSyncDialog(page)).toContainText(
        `${visibleWeekDay(0)} to ${visibleWeekDay(6)}`
    );

    // Assert: and the server now agrees that everything with a ticket is logged
    const statuses = await getJiraSyncStatusViaApi(ctx, today, today);
    const states = Object.values(statuses).map((status) => status.state);
    expect(states.filter((state) => state === 'synced')).toHaveLength(4);
    expect(states.filter((state) => state === 'no_reference')).toHaveLength(1);

    // Assert: reopening finds nothing left to do, which is the reconciliation closing the loop
    await page.getByTestId('jira_sync_close').click();
    await openJiraSyncDialog(page);
    await expect(page.getByTestId('jira_sync_nothing_to_send')).toBeVisible();
});

test('test that moving an entry to another day previews as an update, not delete and create', async ({
    page,
}) => {
    // Arrange
    // The dialog seeds from the visible week, so today and tomorrow must both be on screen -
    // the last visible column is Sunday, per the Monday week start.
    test.skip(localWeekday() === 0, 'Tomorrow is outside the visible week on a Sunday');
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    const tomorrow = localDate(1);
    const entry = await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 moved a day later',
    });
    await runJiraSyncViaApi(ctx, today, today);

    // Act: the worklog's identity has to survive the date edit - its hash does not
    const response = await ctx.request.put(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/time-entries/${entry.id}`,
        {
            data: {
                member_id: ctx.memberId,
                start: localTimestamp(tomorrow, 9),
                end: localTimestamp(tomorrow, 10),
                description: 'PROJ-1 moved a day later',
            },
        }
    );
    expect(response.status()).toBe(200);
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await openJiraSyncDialog(page);

    // Assert: one re-dating update - nothing deleted, nothing duplicated
    await expect(planRow(page, 'update')).toHaveAttribute('data-issue-key', 'PROJ-1');
    await expect(page.getByTestId('jira_sync_plan_row')).toHaveCount(1);
    await expect(page.getByTestId('jira_sync_confirm')).toContainText('Sync 1 to Jira');
});

test('test that a jira issue the site rejects is reported per worklog', async ({ page }) => {
    // Arrange
    // One bad issue key must not stop the rest of the week reaching Jira, so the run finishes
    // and names the item that failed rather than failing as a whole.
    test.setTimeout(60000);
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: `${JIRA_FAILING_ISSUE_KEY} this issue does not exist`,
    });
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 10),
        end: localTimestamp(today, 11),
        description: 'PROJ-7 this one is fine',
    });

    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await openJiraSyncDialog(page);
    await expect(page.getByTestId('jira_sync_plan_row')).toHaveCount(2);

    // Act
    await page.getByTestId('jira_sync_confirm').click();

    // Assert
    const body = page.getByTestId('jira_sync_body');
    await expect(body).toContainText('1 could not be logged', { timeout: 30000 });
    await expect(body).toContainText(JIRA_FAILING_ISSUE_KEY);

    // Assert: the good one still went, so a typo costs one worklog and not the week
    const statuses = await getJiraSyncStatusViaApi(ctx, today, today);
    const states = Object.values(statuses).map((status) => status.state);
    expect(states.filter((state) => state === 'synced')).toHaveLength(1);
    expect(states.filter((state) => state === 'pending')).toHaveLength(1);
});

// ──────────────────────────────────────────────────
// The sync cutoff
// ──────────────────────────────────────────────────

test('test that work before the sync cutoff is treated as already logged', async ({ page }) => {
    // Arrange
    test.setTimeout(60000);
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    const yesterday = localDate(-1);

    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(yesterday, 9),
        end: localTimestamp(yesterday, 10),
        description: 'PROJ-5 imported from the old tracker',
    });
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-6 logged here',
    });

    // Act: everything before today was already sent by whatever we imported from
    await setJiraSyncFromDateViaApi(ctx, today);
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await openJiraSyncDialog(page);

    // Act: widen the range so that yesterday is in it whatever day of the week this runs on -
    // the calendar's own week starts on Monday, and would not show yesterday on a Monday
    await page.getByTestId('jira_sync_range').getByRole('button').click();
    await page.getByRole('button', { name: 'Last 14 days', exact: true }).click();
    await expect(page.getByTestId('jira_sync_loading')).toHaveCount(0);

    // Assert: only today's work is offered, and yesterday's is explained rather than dropped
    await expect(page.getByTestId('jira_sync_plan_row')).toHaveCount(1);
    await expect(planRow(page, 'create')).toHaveAttribute('data-issue-key', 'PROJ-6');
    await expect(page.getByTestId('jira_sync_other_skipped')).toContainText(
        '1 other entry not eligible'
    );

    // Act
    await page.getByTestId('jira_sync_confirm').click();
    await expect(page.getByTestId('jira_sync_success')).toBeVisible({ timeout: 30000 });

    // Assert: the pre-cutoff entry was never sent, and is not flagged as something to fix
    const statuses = await getJiraSyncStatusViaApi(ctx, yesterday, today);
    const byState = Object.values(statuses).map((status) => status.state);
    expect(byState.filter((state) => state === 'synced')).toHaveLength(1);
    expect(byState.filter((state) => state === 'ignored')).toHaveLength(1);
    expect(byState).not.toContain('pending');
});

test('test that the sync cutoff can be set and cleared from the profile card', async ({ page }) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');

    // Act
    await expect(page.getByTestId('jira_sync_from_date')).toBeEditable();
    await page.getByTestId('jira_sync_from_date').fill(today);
    await page.getByTestId('jira_save_sync_from_date').click();

    // Assert
    await expect(page.getByText('Jira sync settings saved')).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('jira_sync_from_date')).toHaveValue(today);

    // Act: clearing it means everything is considered again
    await page.getByTestId('jira_sync_from_date').fill('');
    await page.getByTestId('jira_save_sync_from_date').click();
    await page.reload();

    // Assert
    await expect(page.getByTestId('jira_sync_from_date')).toHaveValue('');
});

// ──────────────────────────────────────────────────
// The sync state indicators
// ──────────────────────────────────────────────────

test('test that the calendar shows pending, synced and outdated states', async ({ page }) => {
    // Arrange
    test.setTimeout(90000);
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 needs logging',
    });

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');

    // Assert: a ticketed entry nothing has been logged for is pending, a hollow dot
    await expect(page.getByTestId('sync_indicator_pending').locator('visible=true')).toHaveCount(1);
    await expect(page.getByTestId('sync_indicator_synced').locator('visible=true')).toHaveCount(0);
    // The day header summarises: something on this day still needs logging
    const todayHeaderDot = page
        .locator(`.fc-col-header-cell[data-date="${today}"]`)
        .getByTestId('day_sync_indicator');
    await expect(todayHeaderDot).toHaveAttribute('data-sync-state', 'attention');

    // Act
    await openJiraSyncDialog(page);
    await page.getByTestId('jira_sync_confirm').click();
    await expect(page.getByTestId('jira_sync_success')).toBeVisible({ timeout: 30000 });
    await page.getByTestId('jira_sync_close').click();

    // Assert: the dots refresh from the run finishing, with no reload
    await expect(page.getByTestId('sync_indicator_synced').locator('visible=true')).toHaveCount(1);
    await expect(page.getByTestId('sync_indicator_pending').locator('visible=true')).toHaveCount(0);
    // Everything on the day is logged now, so its header dot turns green - also without a reload
    await expect(todayHeaderDot).toHaveAttribute('data-sync-state', 'synced');

    // Act: more time on the same ticket and day makes what Jira holds wrong rather than missing
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 11),
        end: localTimestamp(today, 12),
        description: 'PROJ-1 needs logging',
    });
    await page.reload();

    // Assert
    await expect(page.getByTestId('sync_indicator_outdated').locator('visible=true')).toHaveCount(
        2
    );
    await expect(page.getByTestId('sync_indicator_synced').locator('visible=true')).toHaveCount(0);
    // And the day is no longer fully logged, so its header dot goes back to grey
    await expect(todayHeaderDot).toHaveAttribute('data-sync-state', 'attention');
});

test('test that changing the ticket number reads as outdated, not as never logged', async ({
    page,
}) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    const entry = await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 wears the wrong ticket',
    });
    await runJiraSyncViaApi(ctx, today, today);

    // Act: the sync will have to delete and recreate - Jira cannot move a worklog between
    // issues - but the dot must say "changed", because this work was logged and got edited
    const response = await ctx.request.put(
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}/time-entries/${entry.id}`,
        {
            data: {
                member_id: ctx.memberId,
                start: localTimestamp(today, 9),
                end: localTimestamp(today, 10),
                description: 'PROJ-2 wears the wrong ticket',
            },
        }
    );
    expect(response.status()).toBe(200);
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');

    // Assert: amber "changed since logged", not a hollow "never logged"
    const dot = page.getByTestId('sync_indicator_outdated').locator('visible=true');
    await expect(dot).toHaveCount(1);
    await expect(dot).toHaveAttribute('aria-label', /Changed since it was logged/);
    await expect(page.getByTestId('sync_indicator_pending').locator('visible=true')).toHaveCount(0);
});

test('test that the time list and the timesheet show the same sync states', async ({ page }) => {
    // Arrange
    // The three views share useJiraIndicators precisely so they cannot drift apart, and this is
    // what says so.
    test.setTimeout(60000);
    const ctx = await jiraOrganization(page);
    await connectJiraViaApi(ctx);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 already logged',
    });
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 10),
        end: localTimestamp(today, 11),
        description: 'PROJ-2 not logged yet',
    });
    await runJiraSyncViaApi(ctx, today, today);
    // Only added afterwards, so it is the one thing still pending
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 12),
        end: localTimestamp(today, 13),
        description: 'PROJ-3 added after the sync',
    });

    const synced = page.getByTestId('sync_indicator_synced').locator('visible=true');
    const pending = page.getByTestId('sync_indicator_pending').locator('visible=true');

    // Act & Assert: the time list
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');
    await expect(page.getByTestId('time_entry_row').first()).toBeVisible();
    await expect(synced).toHaveCount(2);
    await expect(pending).toHaveCount(1);

    // Act & Assert: the timesheet, from the same query. A cell stands for every entry of that
    // row on that day and shows no descriptions, so it surfaces the entry that most needs
    // attention rather than one dot per entry - here, the one still to be logged.
    await page.goto(PLAYWRIGHT_BASE_URL + '/timesheet');
    await expect(page.getByTestId('timesheet_view')).toBeVisible();
    await expect(page.getByTestId('timesheet_cell').first()).toBeVisible();
    await expect(pending).toHaveCount(1);
    await expect(synced).toHaveCount(0);

    // Act: once nothing is outstanding the same cell turns green
    await runJiraSyncViaApi(ctx, today, today);
    await page.reload();
    await expect(page.getByTestId('timesheet_cell').first()).toBeVisible();

    // Assert
    await expect(synced).toHaveCount(1);
    await expect(pending).toHaveCount(0);
});

// ──────────────────────────────────────────────────
// The "no ticket" dots
//
// Detecting a missing ticket is entirely local, so these need no Jira account. The setting
// behind them is a persisted per user one, so it is set through the API and read from the shared
// props on the next page load.
// ──────────────────────────────────────────────────

/*
 * Regression: the red dot used to be fetched from the server, so an entry created or edited in
 * the app kept its old dot until the status query happened to refetch - in practice until the
 * page was reloaded. Nothing here reloads, deliberately. If the dots ever go back to being
 * server-derived without invalidation, this fails.
 */
test('test that the missing ticket dot updates without a reload when entries change', async ({
    page,
}) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await setShowMissingTicketHintsViaApi(ctx, true);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 anchor entry',
    });

    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    const anchor = page.locator('.fc-event').filter({ hasText: 'PROJ-1 anchor entry' }).first();
    await scrollIntoViewCentred(anchor);
    await expect(anchor).toBeVisible();

    const missingDots = page
        .getByTestId('sync_indicator_missing-reference')
        .locator('visible=true');
    // The anchor has a ticket, so nothing is marked yet
    await expect(missingDots).toHaveCount(0);

    // Act: create an entry with no ticket, from the calendar's own context menu
    const box = await anchor.boundingBox();
    expect(box).not.toBeNull();
    const clickY = box!.y + box!.height + 20;
    expect(clickY).toBeLessThan(page.viewportSize()!.height);
    await page.mouse.click(box!.x + box!.width / 2, clickY, { button: 'right' });
    await expect(page.getByRole('menu')).toBeVisible();
    await page.getByRole('menuitem', { name: 'Create Time Entry' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.locator('#description').fill('brand new entry with no ticket');
    await page.getByRole('button', { name: 'Create Time Entry' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Assert: the dot is there straight away, with no navigation of any kind
    await expect(
        page.locator('.fc-event').filter({ hasText: 'brand new entry with no ticket' }).first()
    ).toBeVisible();
    await expect(missingDots).toHaveCount(1);

    // Act: give it a ticket by editing it
    await page
        .locator('.fc-event')
        .filter({ hasText: 'brand new entry with no ticket' })
        .first()
        .click({ button: 'right' });
    await expect(page.getByRole('menu')).toBeVisible();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.locator('#description').fill('OPS-9 now it has a ticket');
    await page.getByRole('button', { name: 'Update Time Entry' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Assert: and the dot clears itself, again without a reload
    await expect(
        page.locator('.fc-event').filter({ hasText: 'OPS-9 now it has a ticket' }).first()
    ).toBeVisible();
    await expect(missingDots).toHaveCount(0);
});

test('test that the missing ticket dot updates without a reload in the time list', async ({
    page,
}) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    await setShowMissingTicketHintsViaApi(ctx, true);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'PROJ-1 has a ticket',
    });
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');

    const missingDots = page
        .getByTestId('sync_indicator_missing-reference')
        .locator('visible=true');
    await expect(page.getByTestId('time_entry_row').first()).toBeVisible();
    await expect(missingDots).toHaveCount(0);

    // Act: take the ticket back out, in place
    await page.getByTestId('time_entry_row').first().click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.locator('#description').fill('ticket removed');
    await page.getByRole('button', { name: 'Update Time Entry' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Assert
    await expect(missingDots).toHaveCount(1);
});

test('test that the edit dialog shows the detected ticket and follows the description', async ({
    page,
}) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    // The box only appears for someone who has opted in - here, via the missing-ticket dots
    await setShowMissingTicketHintsViaApi(ctx, true);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 11),
        description: 'PROJ-42 fix the login redirect',
    });
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');

    // Act
    await page.getByTestId('time_entry_row').first().click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    // Assert
    await expect(page.getByTestId('time_entry_external_reference')).toHaveText('PROJ-42');
    // The ticket can be opened on the organization's site, in a new tab
    const openTicket = page.getByTestId('time_entry_external_reference_open');
    await expect(openTicket).toHaveAttribute('href', SITE_URL + '/browse/PROJ-42');
    await expect(openTicket).toHaveAttribute('target', '_blank');

    // Act: the badge tracks the description as it is typed, so a fix is confirmed immediately
    await page.locator('#description').fill('no ticket in here now');

    // Assert
    await expect(page.getByTestId('time_entry_external_reference')).toHaveCount(0);
    await expect(page.getByTestId('time_entry_external_reference_missing')).toBeVisible();

    // Act
    await page.locator('#description').fill('OPS-7 and back again');

    // Assert
    await expect(page.getByTestId('time_entry_external_reference')).toHaveText('OPS-7');
    await expect(page.getByTestId('time_entry_external_reference_open')).toHaveAttribute(
        'href',
        SITE_URL + '/browse/OPS-7'
    );
});

test('test that the edit dialog shows no ticket box until you opt in', async ({ page }) => {
    // Arrange
    // The organization uses Jira, but this member has neither connected an account nor turned on
    // the missing-ticket dots, so telling them about tickets would be unsolicited noise.
    const ctx = await jiraOrganization(page);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 11),
        description: 'PROJ-42 fix the login redirect',
    });
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');

    // Act
    await page.getByTestId('time_entry_row').first().click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    // Assert: neither box, even though the description does contain a ticket
    await expect(page.getByTestId('time_entry_external_reference')).toHaveCount(0);
    await expect(page.getByTestId('time_entry_external_reference_missing')).toHaveCount(0);

    // Act: opting in from the profile card brings it back
    await page.keyboard.press('Escape');
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await page.getByTestId('jira_missing_ticket_toggle').click();
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');
    await page.getByTestId('time_entry_row').first().click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    // Assert
    await expect(page.getByTestId('time_entry_external_reference')).toHaveText('PROJ-42');
});

test('test that the edit dialog shows no ticket box when the organization has no jira site', async ({
    page,
}) => {
    // Arrange
    const ctx = await setupTestContext(page);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 11),
        description: 'PROJ-42 fix the login redirect',
    });
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');

    // Act
    await page.getByTestId('time_entry_row').first().click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    // Assert: an organization that does not use Jira sees neither box, not "No ticket"
    await expect(page.getByTestId('time_entry_external_reference')).toHaveCount(0);
    await expect(page.getByTestId('time_entry_external_reference_missing')).toHaveCount(0);
});

test('test that the profile toggle marks entries with no ticket everywhere', async ({ page }) => {
    // Arrange
    const ctx = await jiraOrganization(page);
    // Today, at explicit non-overlapping times. Not "yesterday": the week starts Monday, so on
    // a Monday yesterday belongs to the previous week and is not on screen at all. Overlapping
    // entries would share a column and clip their own titles.
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 11),
        description: 'no ticket here',
    });
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 12),
        end: localTimestamp(today, 13),
        description: 'PROJ-42 has a ticket',
    });

    // Scoped to the calendar block: a bare text match also hits the hover tooltip's copy,
    // which is in the DOM but hidden until you hover it
    const untaggedEvent = page.locator('.fc-event').filter({ hasText: 'no ticket here' }).first();
    // The time list renders a desktop and a mobile layout and hides one with a container query,
    // so both are in the DOM. Count what is actually on screen.
    const visibleMissingDots = page
        .getByTestId('sync_indicator_missing-reference')
        .locator('visible=true');

    // Assert: off by default, so nothing is marked
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(untaggedEvent).toBeVisible();
    await expect(visibleMissingDots).toHaveCount(0);

    // Act: turn it on from the profile settings card, with no Jira account connected
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await page.getByTestId('jira_missing_ticket_toggle').click();

    // Assert: the calendar marks the entry without a ticket, and only that one
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(untaggedEvent).toBeVisible();
    await expect(visibleMissingDots).toHaveCount(1);
    // Nothing is logged to Jira, but a ticketed entry must not be marked as a problem either
    await expect(page.getByTestId('sync_indicator_pending').locator('visible=true')).toHaveCount(0);

    // Assert: and so does the time list, from the same setting
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');
    await expect(page.getByTestId('time_entry_row').first()).toBeVisible();
    await expect(visibleMissingDots).toHaveCount(1);

    // Act: turning it off clears them again
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await page.getByTestId('jira_missing_ticket_toggle').click();

    // Assert
    await page.goto(PLAYWRIGHT_BASE_URL + '/calendar');
    await expect(untaggedEvent).toBeVisible();
    await expect(visibleMissingDots).toHaveCount(0);
});

test('test that the missing ticket setting follows the user rather than the browser', async ({
    page,
}) => {
    // Arrange
    // It used to live in localStorage, which meant signing in elsewhere silently turned the dots
    // back off. Clearing the browser's storage must now change nothing.
    const ctx = await jiraOrganization(page);
    await setShowMissingTicketHintsViaApi(ctx, true);
    const today = localDate();
    await createTimeEntryWithTimestampsViaApi(ctx, {
        start: localTimestamp(today, 9),
        end: localTimestamp(today, 10),
        description: 'no ticket here either',
    });

    const visibleMissingDots = page
        .getByTestId('sync_indicator_missing-reference')
        .locator('visible=true');

    // Act
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');
    await expect(page.getByTestId('time_entry_row').first()).toBeVisible();

    // Assert
    await expect(visibleMissingDots).toHaveCount(1);
    await expect(page.getByTestId('jira_missing_ticket_toggle')).toHaveCount(0);

    // Act
    await page.evaluate(() => window.localStorage.clear());
    await page.goto(PLAYWRIGHT_BASE_URL + '/time');

    // Assert
    await expect(page.getByTestId('time_entry_row').first()).toBeVisible();
    await expect(visibleMissingDots).toHaveCount(1);
    // And the card agrees, because both read the same shared prop
    await page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await expect(page.getByTestId('jira_missing_ticket_toggle')).toBeChecked();
});
