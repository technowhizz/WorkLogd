import { describe, expect, it, vi } from 'vitest';
import type { JiraSyncPlan } from '@/packages/api/src';
import { useJiraSync } from './useJiraQuery';

const apiMock = vi.hoisted(() => ({
    getJiraSyncPreview: vi.fn(),
}));

vi.mock('@/packages/api/src', () => ({
    api: apiMock,
}));

vi.mock('@tanstack/vue-query', () => ({
    useQuery: vi.fn(),
    useMutation: vi.fn(() => ({ mutateAsync: vi.fn(), isPending: false })),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));

vi.mock('@/utils/notification', () => ({
    useNotificationsStore: () => ({
        addNotification: vi.fn(),
        handleApiRequestNotifications: vi.fn(),
    }),
}));

vi.mock('@/utils/useUser', () => ({
    getCurrentOrganizationId: () => 'organization-1',
    getCurrentMembershipId: () => 'membership-1',
}));

function plan(id: string): JiraSyncPlan {
    return { items: [], skipped: [], start: id, end: id } as unknown as JiraSyncPlan;
}

/** A promise plus the handle to settle it later, so response order can be controlled. */
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });
    return { promise, resolve };
}

describe('useJiraSync', () => {
    it('ignores a plan response that a newer range has already superseded', async () => {
        const first = deferred<{ data: JiraSyncPlan }>();
        const second = deferred<{ data: JiraSyncPlan }>();
        apiMock.getJiraSyncPreview
            .mockReturnValueOnce(first.promise)
            .mockReturnValueOnce(second.promise);

        const { plan: currentPlan, isLoadingPlan, loadPlan } = useJiraSync();

        const firstCall = loadPlan('2026-08-03', '2026-08-09');
        const secondCall = loadPlan('2026-08-10', '2026-08-16');

        // The newer range answers first, then the stale one arrives late
        second.resolve({ data: plan('second') });
        await secondCall;
        first.resolve({ data: plan('first') });
        await firstCall;

        expect(currentPlan.value?.start).toBe('second');
        expect(isLoadingPlan.value).toBe(false);
    });

    it('keeps the spinner up when a superseded request settles', async () => {
        const first = deferred<{ data: JiraSyncPlan }>();
        const second = deferred<{ data: JiraSyncPlan }>();
        apiMock.getJiraSyncPreview
            .mockReturnValueOnce(first.promise)
            .mockReturnValueOnce(second.promise);

        const { isLoadingPlan, loadPlan } = useJiraSync();

        const firstCall = loadPlan('2026-08-03', '2026-08-09');
        const secondCall = loadPlan('2026-08-10', '2026-08-16');

        first.resolve({ data: plan('first') });
        await firstCall;

        expect(isLoadingPlan.value).toBe(true);

        second.resolve({ data: plan('second') });
        await secondCall;

        expect(isLoadingPlan.value).toBe(false);
    });

    it('drops a plan that arrives after the dialog was closed', async () => {
        const inFlight = deferred<{ data: JiraSyncPlan }>();
        apiMock.getJiraSyncPreview.mockReturnValueOnce(inFlight.promise);

        const { plan: currentPlan, isLoadingPlan, loadPlan, reset } = useJiraSync();

        const call = loadPlan('2026-08-10', '2026-08-16');
        reset();
        inFlight.resolve({ data: plan('late') });
        await call;

        expect(currentPlan.value).toBeNull();
        expect(isLoadingPlan.value).toBe(false);
    });

    it('reports a failure of the newest request only', async () => {
        const first = deferred<{ data: JiraSyncPlan }>();
        apiMock.getJiraSyncPreview
            .mockReturnValueOnce(Promise.reject(new Error('boom')))
            .mockReturnValueOnce(first.promise);

        const { error, loadPlan } = useJiraSync();

        const failing = loadPlan('2026-08-03', '2026-08-09');
        const succeeding = loadPlan('2026-08-10', '2026-08-16');
        await failing;
        first.resolve({ data: plan('second') });
        await succeeding;

        expect(error.value).toBeNull();
    });
});
