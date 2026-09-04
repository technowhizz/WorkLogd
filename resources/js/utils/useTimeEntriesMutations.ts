import { useMutation, useQueryClient } from '@tanstack/vue-query';
import {
    api,
    type CreateTimeEntryBody,
    type TimeEntry,
    type UpdateMultipleTimeEntriesChangeset,
} from '@/packages/api/src';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import { restorablePayload, useTimeEntryUndo } from '@/utils/useTimeEntryUndo';

export function useTimeEntriesMutations() {
    const queryClient = useQueryClient();
    const { handleApiRequestNotifications, addNotification, addActionableNotification } =
        useNotificationsStore();
    const { remember, undo } = useTimeEntryUndo();

    /**
     * The entries behind a set of ids, taken from the query cache.
     *
     * Read before the delete so an undo has something to put back. Going through the cache rather
     * than changing every deleteTimeEntry(id) call site keeps this to one file - and the entries
     * are always cached, because the row being deleted was rendered from that cache.
     */
    function cachedEntries(ids: string[]): TimeEntry[] {
        const wanted = new Set(ids);
        const found = new Map<string, TimeEntry>();

        for (const [, data] of queryClient.getQueriesData<{ data?: TimeEntry[] }>({
            queryKey: ['timeEntries'],
        })) {
            for (const entry of data?.data ?? []) {
                if (wanted.has(entry.id)) {
                    found.set(entry.id, entry);
                }
            }
        }

        return [...found.values()];
    }

    function offerUndo(entries: TimeEntry[], title: string) {
        if (entries.length === 0) {
            // Nothing was cached, so there is nothing honest to offer. Better a plain confirmation
            // than an Undo button that would quietly do nothing.
            addNotification('success', title);

            return;
        }

        remember({
            entries: entries.map(restorablePayload),
            label: title,
            restore: (entry) => createTimeEntry(entry),
        });

        addActionableNotification('success', title, {
            label: 'Undo',
            run: async () => {
                await undo();
            },
        });
    }

    const { mutateAsync: createTimeEntry } = useMutation({
        mutationFn: async (timeEntry: Omit<CreateTimeEntryBody, 'member_id'>) => {
            const organizationId = getCurrentOrganizationId();
            const memberId = getCurrentMembershipId();
            if (organizationId && memberId !== undefined) {
                const newTimeEntry = {
                    ...timeEntry,
                    member_id: memberId,
                } as CreateTimeEntryBody;

                return await handleApiRequestNotifications(
                    () =>
                        api.createTimeEntry(newTimeEntry, {
                            params: {
                                organization: organizationId,
                            },
                        }),
                    'Time entry created successfully',
                    'Failed to create time entry'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
            // The Jira sync states are computed from these entries, so they are stale now too.
            // The "no ticket" dots are derived on the client and need no invalidation.
            queryClient.invalidateQueries({ queryKey: ['jira', 'syncStatus'] });
        },
    });

    const { mutateAsync: updateTimeEntry } = useMutation({
        mutationFn: async (timeEntry: TimeEntry) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                return await handleApiRequestNotifications(
                    () =>
                        api.updateTimeEntry(timeEntry, {
                            params: {
                                organization: organizationId,
                                timeEntry: timeEntry.id,
                            },
                        }),
                    'Time entry updated successfully',
                    'Failed to update time entry'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
            // The Jira sync states are computed from these entries, so they are stale now too.
            // The "no ticket" dots are derived on the client and need no invalidation.
            queryClient.invalidateQueries({ queryKey: ['jira', 'syncStatus'] });
        },
    });

    const { mutateAsync: updateTimeEntries } = useMutation({
        mutationFn: async ({
            ids,
            changes,
        }: {
            ids: string[];
            changes: UpdateMultipleTimeEntriesChangeset;
        }) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                const response = await handleApiRequestNotifications(
                    () =>
                        api.updateMultipleTimeEntries(
                            {
                                ids: ids,
                                changes: changes,
                            },
                            {
                                params: {
                                    organization: organizationId,
                                },
                            }
                        ),
                    undefined,
                    'Failed to update time entries'
                );
                // The endpoint applies the changeset per entry and skips entries it can't
                // apply it to (e.g. breaks with a project/tags/billable change) — a 200
                // with their ids in `error`. Surface that instead of claiming success.
                const skippedCount = response?.error.length ?? 0;
                if (skippedCount > 0) {
                    addNotification(
                        'error',
                        `${skippedCount} of ${ids.length} time entries ${skippedCount === 1 ? 'was' : 'were'} skipped`,
                        'No changes were applied to the skipped entries — break entries can not have a project or tags, or be billable.'
                    );
                } else {
                    addNotification('success', 'Time entries updated successfully');
                }
                return response;
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
            // The Jira sync states are computed from these entries, so they are stale now too.
            // The "no ticket" dots are derived on the client and need no invalidation.
            queryClient.invalidateQueries({ queryKey: ['jira', 'syncStatus'] });
        },
    });

    const { mutateAsync: deleteTimeEntry } = useMutation({
        mutationFn: async (timeEntryId: string) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                // Captured before the request, because afterwards it is gone from the cache too.
                const deleted = cachedEntries([timeEntryId]);

                const response = await handleApiRequestNotifications(
                    () =>
                        api.deleteTimeEntry(undefined, {
                            params: {
                                organization: organizationId,
                                timeEntry: timeEntryId,
                            },
                        }),
                    undefined,
                    'Failed to delete time entry'
                );

                offerUndo(deleted, 'Time entry deleted');

                return response;
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
            // The Jira sync states are computed from these entries, so they are stale now too.
            // The "no ticket" dots are derived on the client and need no invalidation.
            queryClient.invalidateQueries({ queryKey: ['jira', 'syncStatus'] });
        },
    });

    const { mutateAsync: deleteTimeEntries } = useMutation({
        mutationFn: async (timeEntries: TimeEntry[]) => {
            const organizationId = getCurrentOrganizationId();
            const timeEntryIds = timeEntries.map((entry) => entry.id);
            if (organizationId) {
                const response = await handleApiRequestNotifications(
                    () =>
                        api.deleteTimeEntries(undefined, {
                            queries: {
                                ids: timeEntryIds,
                            },
                            params: {
                                organization: organizationId,
                            },
                        }),
                    undefined,
                    'Failed to delete time entries'
                );

                offerUndo(
                    timeEntries,
                    timeEntries.length === 1
                        ? 'Time entry deleted'
                        : `${timeEntries.length} time entries deleted`
                );

                return response;
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
            // The Jira sync states are computed from these entries, so they are stale now too.
            // The "no ticket" dots are derived on the client and need no invalidation.
            queryClient.invalidateQueries({ queryKey: ['jira', 'syncStatus'] });
        },
    });

    return {
        createTimeEntry,
        updateTimeEntry,
        updateTimeEntries,
        deleteTimeEntry,
        deleteTimeEntries,
    };
}
