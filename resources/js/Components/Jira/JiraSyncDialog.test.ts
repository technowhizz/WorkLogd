import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { config, mount } from '@vue/test-utils';
import { nextTick, type Ref } from 'vue';
import type { JiraSyncPlan } from '@/packages/api/src';
import JiraSyncDialog from './JiraSyncDialog.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';

type SyncState = {
    plan: Ref<JiraSyncPlan | null>;
    run: Ref<null>;
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

vi.mock('@/utils/useJiraQuery', async () => {
    const { ref } = await import('vue');

    return {
        useJiraSync: () => {
            const state = {
                plan: ref(null),
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
                state.isLoadingPlan.value = false;
            });

            return { ...state, loadPlan: sync.loadPlan, start: sync.start, reset: sync.reset };
        },
    };
});

function syncState(): SyncState {
    return sync.state as SyncState;
}

function planWithOneCreate(): JiraSyncPlan {
    return {
        items: [
            {
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
            },
        ],
        skipped: [],
    } as unknown as JiraSyncPlan;
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
