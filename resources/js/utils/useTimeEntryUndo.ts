import { ref } from 'vue';
import type { CreateTimeEntryBody, TimeEntry } from '@/packages/api/src';

/**
 * The last deletion, held so it can be put back.
 *
 * Module scope on purpose: the toast that offers the undo, the keyboard shortcut that triggers
 * it, and the mutation that recorded it are three different components, and they all have to be
 * talking about the same deletion.
 *
 * Restoring creates a new entry from the old one's data rather than resurrecting the row, so the
 * entry comes back with a new id. Nothing in the app keys off a time entry id across a delete -
 * Jira worklog membership is rebuilt from the current entries on the next sync - so the only
 * visible difference is that it is, strictly, a new record of the same work.
 */
export type UndoableDeletion = {
    entries: Array<Omit<CreateTimeEntryBody, 'member_id'>>;
    /** What to say on the toast and when the shortcut fires. */
    label: string;
    restore: (entry: Omit<CreateTimeEntryBody, 'member_id'>) => Promise<unknown> | unknown;
};

const pending = ref<UndoableDeletion | null>(null);
const restoring = ref(false);

/** Everything a deleted entry needs to come back as it was. */
export function restorablePayload(entry: TimeEntry): Omit<CreateTimeEntryBody, 'member_id'> {
    return {
        start: entry.start,
        end: entry.end,
        billable: entry.billable,
        type: entry.type,
        description: entry.description,
        project_id: entry.project_id,
        task_id: entry.task_id,
        tags: entry.tags,
    } as Omit<CreateTimeEntryBody, 'member_id'>;
}

export function useTimeEntryUndo() {
    function remember(deletion: UndoableDeletion) {
        pending.value = deletion;
    }

    function forget() {
        pending.value = null;
    }

    function hasSomethingToUndo(): boolean {
        return pending.value !== null && !restoring.value;
    }

    /**
     * Put the last deletion back.
     *
     * Cleared before the restore rather than after, so a second press while the first is still in
     * flight cannot create the entries twice.
     */
    async function undo(): Promise<boolean> {
        const deletion = pending.value;

        if (deletion === null || restoring.value) {
            return false;
        }

        pending.value = null;
        restoring.value = true;

        try {
            for (const entry of deletion.entries) {
                await deletion.restore(entry);
            }

            return true;
        } finally {
            restoring.value = false;
        }
    }

    return { remember, forget, undo, hasSomethingToUndo, pending, restoring };
}
