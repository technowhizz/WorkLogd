import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import TimeEntryUndoShortcut from './TimeEntryUndoShortcut.vue';
import { useTimeEntryUndo, type UndoableDeletion } from '@/utils/useTimeEntryUndo';

function press(key: string, modifiers: Partial<KeyboardEventInit> = {}, target?: EventTarget) {
    const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
        ...modifiers,
    });
    (target ?? document).dispatchEvent(event);

    return event;
}

describe('TimeEntryUndoShortcut', () => {
    let restore: ReturnType<typeof vi.fn>;

    // The component attaches a document listener, so a wrapper left mounted keeps listening into
    // the next test and makes the unmount case pass or fail for the wrong reason.
    let mounted: Array<{ unmount: () => void }> = [];

    function mountShortcut() {
        const wrapper = mount(TimeEntryUndoShortcut, { attachTo: document.body });
        mounted.push(wrapper);

        return wrapper;
    }

    beforeEach(() => {
        setActivePinia(createPinia());
        restore = vi.fn().mockResolvedValue(undefined);
        useTimeEntryUndo().forget();
    });

    afterEach(() => {
        mounted.forEach((wrapper) => wrapper.unmount());
        mounted = [];
    });

    function remember() {
        useTimeEntryUndo().remember({
            entries: [{ start: 'a', end: 'b' } as never],
            label: 'Time entry deleted',
            restore: restore as unknown as UndoableDeletion['restore'],
        });
    }

    it('undoes on Ctrl+Z', async () => {
        mountShortcut();
        remember();

        press('z', { ctrlKey: true });
        await vi.waitFor(() => expect(restore).toHaveBeenCalledOnce());
    });

    it('undoes on Cmd+Z', async () => {
        mountShortcut();
        remember();

        press('z', { metaKey: true });
        await vi.waitFor(() => expect(restore).toHaveBeenCalledOnce());
    });

    it('ignores a bare Z', async () => {
        mountShortcut();
        remember();

        press('z');
        expect(restore).not.toHaveBeenCalled();
    });

    it('leaves Shift+Ctrl+Z alone, since that means redo', async () => {
        mountShortcut();
        remember();

        press('z', { ctrlKey: true, shiftKey: true });
        expect(restore).not.toHaveBeenCalled();
    });

    it('does not steal undo from a text field', async () => {
        mountShortcut();
        remember();
        const input = document.createElement('textarea');
        document.body.appendChild(input);

        press('z', { ctrlKey: true }, input);

        // Ctrl+Z while typing a description belongs to the description.
        expect(restore).not.toHaveBeenCalled();
        input.remove();
    });

    it('does nothing when there is no deletion to undo', async () => {
        mountShortcut();

        const event = press('z', { ctrlKey: true });

        expect(restore).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('stops listening once unmounted', async () => {
        const wrapper = mountShortcut();
        remember();
        wrapper.unmount();

        press('z', { ctrlKey: true });
        expect(restore).not.toHaveBeenCalled();
    });
});
