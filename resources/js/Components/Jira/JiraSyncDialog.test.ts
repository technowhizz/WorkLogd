import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { config, mount } from '@vue/test-utils';
import { nextTick, type Ref } from 'vue';
import type { JiraSyncPlan } from '@/packages/api/src';
import JiraSyncDialog from './JiraSyncDialog.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';

type SyncState = {
    plan: Ref<JiraSyncPlan | null>;
    allowance: Ref<{
        worklogs_per_week: number | null;
        worklogs_remaining: number | null;
        resets_at: string;
    } | null>;
    run: Ref<Record<string, unknown> | null>;
    isLoadingPlan: Ref<boolean>;
    isSyncing: Ref<boolean>;
    error: Ref<string | null>;
};

const sync = vi.hoisted(() => ({
    loadPlan: vi.fn(),
    start: vi.fn(),
    reset: vi.fn(),
    state: null as unknown,
}));

// The delete warning names the app, which reads Inertia's page props - absent under mount().
vi.mock('@/utils/appName', async () => {
    const { computed } = await import('vue');

    return { useAppName: () => computed(() => "WorkLog'd") };
});

vi.mock('@/utils/useJiraQuery', async () => {
    const { ref } = await import('vue');

    return {
        useJiraSync: () => {
            const state = {
                plan: ref(null),
                allowance: ref(null),
                run: ref(null),
                isLoadingPlan: ref(false),
                isSyncing: ref(false),
                error: ref(null),
            };
            sync.state = state;
            // reset() throws the plan away, exactly as the real composable does - it is what
            // makes reopening depend on the dialog asking for a new one
            sync.reset.mockImplementation(() => {
                state.plan.value = null;
                state.allowance.value = null;
                state.isLoadingPlan.value = false;
            });

            return { ...state, loadPlan: sync.loadPlan, start: sync.start, reset: sync.reset };
        },
    };
});

function syncState(): SyncState {
    return sync.state as SyncState;
}

/** One hour, created. Spread it to vary an item without repeating every field. */
function createItem(): Record<string, unknown> {
    return {
        action: 'create',
        issue_key: 'PROJ-1',
        work_date: '2026-08-10',
        comment: 'a thing',
        group_hash: 'hash-1',
        duration: 3600,
        previous_duration: null,
        started: '2026-08-10T09:00:00.000+0000',
        jira_worklog_id: null,
        time_entry_ids: ['entry-1'],
        status: null,
        error: null,
    };
}

function planWithItems(items: Array<Record<string, unknown>>): JiraSyncPlan {
    return { items, skipped: [] } as unknown as JiraSyncPlan;
}

function planWithOneCreate(): JiraSyncPlan {
    return planWithItems([createItem()]);
}

function mountDialog(props: { show: boolean; startDate: string; endDate: string }) {
    return mount(JiraSyncDialog, { props, shallow: true });
}

describe('JiraSyncDialog', () => {
    beforeEach(() => {
        config.global.renderStubDefaultSlot = true;
        sync.loadPlan.mockClear();
        sync.reset.mockClear();
    });

    afterEach(() => {
        config.global.renderStubDefaultSlot = false;
        vi.mocked(window.getTimezoneSetting).mockReturnValue('UTC');
    });

    it('loads a plan every time it is opened, even when the range is unchanged', async () => {
        const wrapper = mountDialog({
            show: false,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });

        await wrapper.setProps({ show: true });
        expect(sync.loadPlan).toHaveBeenCalledTimes(1);
        expect(sync.loadPlan).toHaveBeenLastCalledWith('2026-08-10', '2026-08-16');

        // Dismissing throws the plan away; the same range must not stop the next open reloading
        await wrapper.setProps({ show: false });
        await wrapper.setProps({ show: true });

        expect(sync.loadPlan).toHaveBeenCalledTimes(2);
        expect(sync.loadPlan).toHaveBeenLastCalledWith('2026-08-10', '2026-08-16');
    });

    it('picks up the range the calendar moved to while it was closed', async () => {
        const wrapper = mountDialog({
            show: false,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        await wrapper.setProps({ show: true });
        await wrapper.setProps({ show: false });

        // A shortened week (calendar_week_days = 5) on the following week
        await wrapper.setProps({ startDate: '2026-08-17', endDate: '2026-08-21' });
        await wrapper.setProps({ show: true });

        expect(sync.loadPlan).toHaveBeenLastCalledWith('2026-08-17', '2026-08-21');
    });

    it('seeds the range as local days rather than UTC midnight', async () => {
        vi.mocked(window.getTimezoneSetting).mockReturnValue('America/New_York');

        const wrapper = mountDialog({
            show: false,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        await wrapper.setProps({ show: true });

        expect(sync.loadPlan).toHaveBeenLastCalledWith('2026-08-10', '2026-08-16');
    });

    it('shows a spinner over the previous plan instead of replacing the body', async () => {
        const wrapper = mountDialog({
            show: true,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        syncState().plan.value = planWithOneCreate();
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_plan"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="jira_sync_loading"]').exists()).toBe(false);

        syncState().isLoadingPlan.value = true;
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_loading"]').exists()).toBe(true);
        expect(wrapper.findComponent(LoadingSpinner).exists()).toBe(true);
        // The plan stays in the DOM, so nothing below it moves while the new one loads
        expect(wrapper.find('[data-testid="jira_sync_plan"]').exists()).toBe(true);
    });

    it('totals the time being logged next to the sync button', async () => {
        const wrapper = mountDialog({
            show: true,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        syncState().plan.value = planWithOneCreate();
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_total"]').text()).toBe('Total 1h 00min');
    });

    it('leaves deletions out of the total and counts the new size of an update', async () => {
        const wrapper = mountDialog({
            show: true,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        // 1h create + 2h15m update (was 30m, so the previous size must not be what counts),
        // and a 2h delete that must not be added on top
        syncState().plan.value = planWithItems([
            createItem(),
            {
                ...createItem(),
                action: 'update',
                group_hash: 'hash-2',
                duration: 8100,
                previous_duration: 1800,
            },
            { ...createItem(), action: 'delete', group_hash: 'hash-3', duration: 7200 },
        ]);
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_total"]').text()).toBe('Total 3h 15min');
    });

    it('shows no total when there is nothing to send', async () => {
        const wrapper = mountDialog({
            show: true,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        syncState().plan.value = planWithItems([{ ...createItem(), action: 'unchanged' }]);
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_total"]').exists()).toBe(false);
    });

    it('does not offer to sync while a plan is loading', async () => {
        const wrapper = mountDialog({
            show: true,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });
        syncState().plan.value = planWithOneCreate();
        syncState().isLoadingPlan.value = true;
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_confirm"]').attributes('disabled')).toBe(
            'true'
        );
    });
});

/*
 * The free tier's weekly allowance.
 *
 * Worth testing at this level because the consequence of getting it wrong is silent: somebody
 * presses Sync believing their week is going to Jira and only part of it arrives.
 */
describe('JiraSyncDialog weekly allowance', () => {
    beforeEach(() => {
        config.global.renderStubDefaultSlot = true;
        sync.loadPlan.mockClear();
        sync.reset.mockClear();
    });

    afterEach(() => {
        config.global.renderStubDefaultSlot = false;
    });

    async function openWith(
        plan: JiraSyncPlan,
        allowance: SyncState['allowance']['value']
    ) {
        const wrapper = mountDialog({ show: false, startDate: '2026-08-10', endDate: '2026-08-16' });
        await wrapper.setProps({ show: true });
        syncState().plan.value = plan;
        syncState().allowance.value = allowance;
        await nextTick();

        return wrapper;
    }

    it('says nothing about an allowance on a paid plan', async () => {
        const wrapper = await openWith(planWithOneCreate(), null);

        expect(wrapper.find('[data-testid="jira_sync_allowance"]').exists()).toBe(false);
    });

    it('shows what is left when the plan fits inside it', async () => {
        const wrapper = await openWith(planWithOneCreate(), {
            worklogs_per_week: 5,
            worklogs_remaining: 4,
            resets_at: '2026-08-17T00:00:00Z',
        });

        const text = wrapper.find('[data-testid="jira_sync_allowance"]').text();
        expect(text).toContain('4 of your weekly 5');
        expect(text).not.toContain('will not be sent');
    });

    it('warns before syncing when the plan would not fit', async () => {
        const wrapper = await openWith(
            planWithItems([createItem(), { ...createItem(), group_hash: 'hash-2' }]),
            { worklogs_per_week: 5, worklogs_remaining: 1, resets_at: '2026-08-17T00:00:00Z' }
        );

        const text = wrapper.find('[data-testid="jira_sync_allowance"]').text();
        expect(text).toContain('1 new worklog will not be sent');
    });

    it('counts only creates against the allowance, not updates or deletes', async () => {
        const wrapper = await openWith(
            planWithItems([
                createItem(),
                { ...createItem(), action: 'update', group_hash: 'hash-2' },
                { ...createItem(), action: 'delete', group_hash: 'hash-3' },
            ]),
            { worklogs_per_week: 5, worklogs_remaining: 1, resets_at: '2026-08-17T00:00:00Z' }
        );

        // One create against one remaining slot fits, so nothing is withheld even though there
        // are three changes - correcting and removing a worklog costs nothing.
        expect(wrapper.find('[data-testid="jira_sync_allowance"]').text()).not.toContain(
            'will not be sent'
        );
    });

    it('does not claim everything is up to date when items were withheld', async () => {
        const wrapper = await openWith(planWithOneCreate(), null);

        syncState().run.value = {
            status: 'completed',
            done: 1,
            total: 1,
            results: [
                {
                    ...createItem(),
                    status: 'skipped',
                    error: "This week's free allowance of new Jira worklogs is used up.",
                },
            ],
        };
        await nextTick();

        expect(wrapper.find('[data-testid="jira_sync_success"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('not sent');
    });
});
